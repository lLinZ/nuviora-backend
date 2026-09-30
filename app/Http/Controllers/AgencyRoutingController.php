<?php

namespace App\Http\Controllers;

use App\Constants\OrderStatus;
use App\Models\AssignmentPool;
use App\Models\City;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\Status;
use App\Models\User;
use App\Notifications\OrderAssignedNotification;
use App\Services\Agencies\AgencyRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agencias por ciudad (tareas 3c, 6 y 8): qué agencias entregan en cada ciudad y con qué %, la tarifa
 * y el máximo de cada agencia, y la reasignación en bloque de las órdenes de una agencia.
 * Leer: Admin, Gerente y Master. Cambiar: Admin y Master (la reasignación, también la Gerente).
 */
class AgencyRoutingController extends Controller
{
    /** Estados que se pueden pasar de una agencia a otra: todavía no salieron a la calle. */
    private const MOVABLE = [OrderStatus::ASIGNAR_A_AGENCIA, OrderStatus::ASIGNAR_REPARTIDOR, OrderStatus::ASIGNADO_A_REPARTIDOR];

    public function __construct(private AgencyRouter $router) {}

    /** GET /agency-routing */
    public function index(): JsonResponse
    {
        return response()->json(['status' => true, 'data' => $this->payload()]);
    }

    /** PUT /agency-routing/agencies/{agency} { delivery_cost, max_active_orders } */
    public function updateAgency(Request $request, User $agency): JsonResponse
    {
        $this->ensureAgency($agency);
        $data = $request->validate([
            'delivery_cost' => 'required|numeric|min:0|max:1000',
            'max_active_orders' => 'nullable|integer|min:1|max:100000',
        ]);
        $agency->forceFill(['delivery_cost' => $data['delivery_cost'], 'max_active_orders' => $data['max_active_orders'] ?? null])->save();

        return response()->json(['status' => true, 'message' => 'Agencia actualizada', 'data' => $this->payload()]);
    }

    /**
     * PUT /agency-routing/cities/{city} { agencies: [{ agency_id, weight|null, is_active }] }
     * Entre las activas, o todas sin % (reparto parejo) o todas con % que sumen 100.
     */
    public function updateCity(Request $request, City $city): JsonResponse
    {
        $data = $request->validate([
            'agencies' => 'present|array',
            'agencies.*.agency_id' => 'required|integer|distinct|exists:users,id',
            'agencies.*.weight' => 'nullable|numeric|min:0|max:100',
            'agencies.*.is_active' => 'required|boolean',
        ]);
        $rows = collect($data['agencies']);
        $agencyIds = User::whereIn('id', $rows->pluck('agency_id'))->whereHas('role', fn ($q) => $q->where('description', 'Agencia'))->pluck('id');
        if ($agencyIds->count() !== $rows->count()) {
            throw ValidationException::withMessages(['agencies' => 'Solo se pueden agregar usuarios con rol Agencia.']);
        }
        $active = $rows->where('is_active', true);
        $withWeight = $active->filter(fn ($r) => $r['weight'] !== null && $r['weight'] !== '');
        if ($withWeight->isNotEmpty()) {
            if ($withWeight->count() !== $active->count()) {
                throw ValidationException::withMessages(['agencies' => 'Pon el % de todas las agencias activas, o de ninguna (reparto parejo).']);
            }
            $sum = round($withWeight->sum(fn ($r) => (float) $r['weight']), 2);
            if (abs($sum - 100) > 0.01) {
                throw ValidationException::withMessages(['agencies' => "Los % de las agencias activas deben sumar 100 (ahora suman {$sum})."]);
            }
        }

        DB::transaction(function () use ($city, $rows) {
            $city->agencies()->sync($rows->mapWithKeys(fn ($r) => [$r['agency_id'] => [
                'weight' => ($r['weight'] === null || $r['weight'] === '') ? null : round((float) $r['weight'], 2),
                'is_active' => (bool) $r['is_active'],
            ]])->all());
            // La principal (la que todavía leen otras pantallas): la activa de mayor %
            $principal = $rows->where('is_active', true)->sortByDesc(fn ($r) => (float) ($r['weight'] ?? 0))->first();
            $city->forceFill(['agency_id' => $principal['agency_id'] ?? null])->save();
            // Cambió la configuración: el reparto de la ciudad empieza de cero
            AssignmentPool::where('key', "city:{$city->id}")->delete();
        });

        return response()->json(['status' => true, 'message' => "Agencias de {$city->name} guardadas", 'data' => $this->payload()]);
    }

    /** GET /agency-routing/agencies/{agency}/reassign — cuántas órdenes tiene por estado y a quién pueden ir. */
    public function reassignPreview(User $agency): JsonResponse
    {
        $this->ensureAgency($agency);
        $statuses = Status::whereIn('description', self::MOVABLE)->get(['id', 'description']);
        $counts = Order::where('agency_id', $agency->id)->whereIn('status_id', $statuses->pluck('id'))
            ->groupBy('status_id')->selectRaw('status_id, COUNT(*) as c')->pluck('c', 'status_id');
        $cityIds = DB::table('city_agency')->where('agency_id', $agency->id)->pluck('city_id');
        $targets = User::whereHas('role', fn ($q) => $q->where('description', 'Agencia'))
            ->where('id', '!=', $agency->id)
            ->whereIn('id', DB::table('city_agency')->whereIn('city_id', $cityIds)->where('is_active', true)->pluck('agency_id'))
            ->orderBy('names')->get(['id', 'names']);

        return response()->json(['status' => true, 'data' => [
            'agency' => ['id' => $agency->id, 'names' => $agency->names],
            'statuses' => $statuses->map(fn ($s) => ['id' => $s->id, 'description' => $s->description, 'count' => (int) ($counts[$s->id] ?? 0)])->values(),
            'targets' => $targets,
        ]]);
    }

    /**
     * POST /agency-routing/agencies/{agency}/reassign { status_ids, to_agency_ids?, force? } (tarea 8).
     * Cada orden va a otra agencia de su ciudad con el mismo reparto (% y cupo; con force, sin cupo). El
     * stock ya descontado pasa del almacén de una al de la otra (OrderStock). Las que estaban con un
     * repartidor de la agencia anterior vuelven a "Asignar a agencia" sin repartidor.
     */
    public function reassign(Request $request, User $agency): JsonResponse
    {
        $this->ensureAgency($agency);
        $movable = Status::whereIn('description', self::MOVABLE)->pluck('id');
        $data = $request->validate([
            'status_ids' => 'required|array|min:1',
            'status_ids.*' => 'integer|in:' . $movable->implode(','),
            'to_agency_ids' => 'nullable|array',
            'to_agency_ids.*' => 'integer|exists:users,id',
            'force' => 'boolean',
        ], ['status_ids.*.in' => 'Solo se pasan órdenes que todavía no salieron a la calle.']);
        $force = (bool) ($data['force'] ?? false) && in_array(Auth::user()->role?->description, ['Admin', 'Master'], true);

        $targets = collect($data['to_agency_ids'] ?? User::whereHas('role', fn ($q) => $q->where('description', 'Agencia'))->pluck('id'))
            ->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === $agency->id)->values()->all();
        $assignId = Status::where('description', OrderStatus::ASIGNAR_A_AGENCIA)->value('id');

        $moved = 0;
        $byAgency = [];
        $skipped = [];
        foreach (Order::where('agency_id', $agency->id)->whereIn('status_id', $data['status_ids'])->orderBy('id')->get() as $order) {
            [$to, $reason] = $this->router->pick($order, $targets, $force, false);
            if (!$to) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                continue;
            }
            $this->router->place($order, $to);
            $order->deliverer_id = null;
            $order->agency_locked = false;
            $order->status_id = $assignId;
            $order->save(); // OrderStock mueve el stock descontado al almacén nuevo
            OrderActivityLog::create([
                'order_id' => $order->id,
                'user_id' => Auth::id(),
                'action' => 'agency_routing',
                'description' => "Reasignada en bloque de {$agency->names} a {$to->names}" . ($force ? ' (sin respetar el máximo)' : '') . '.',
            ]);
            try {
                $to->notify(new OrderAssignedNotification($order, "Nueva orden asignada a tu agencia: {$order->number_label}"));
            } catch (\Throwable $e) {
                report($e);
            }
            event(new \App\Events\OrderUpdated($order->fresh(['status', 'client', 'agent', 'agency', 'deliverer'])));
            $moved++;
            $byAgency[$to->names] = ($byAgency[$to->names] ?? 0) + 1;
        }

        $labels = ['sin_stock' => 'sin stock en otra agencia', 'todas_llenas' => 'las demás están llenas', 'sin_agencias' => 'su ciudad no tiene otra agencia', 'sin_ciudad' => 'sin ciudad'];
        $left = collect($skipped)->map(fn ($n, $r) => "{$n} " . ($labels[$r] ?? $r))->values()->implode(', ');

        return response()->json([
            'status' => true,
            'message' => "Se pasaron {$moved} órdenes" . ($left ? ". Quedan con {$agency->names}: {$left}" : '') . '.',
            'data' => ['moved' => $moved, 'by_agency' => $byAgency, 'skipped' => $skipped, 'routing' => $this->payload()],
        ]);
    }

    private function payload(): array
    {
        $agencies = User::whereHas('role', fn ($q) => $q->where('description', 'Agencia'))
            ->orderBy('names')->get(['id', 'names', 'color', 'delivery_cost', 'max_active_orders']);
        $active = $this->router->activeCounts($agencies->pluck('id')->all());
        $pendingId = Status::where('description', OrderStatus::PENDIENTE_AGENCIA)->value('id');
        $pending = $pendingId
            ? Order::where('status_id', $pendingId)->groupBy('city_id')->selectRaw('city_id, COUNT(*) as c')->pluck('c', 'city_id')
            : collect();
        $cities = City::with('agencies:id,names,color,max_active_orders')->orderBy('name')->get();
        $citiesByAgency = [];
        foreach ($cities as $city) {
            foreach ($city->agencies as $a) {
                $citiesByAgency[$a->id][] = $city->name;
            }
        }

        return [
            'agencies' => $agencies->map(fn (User $a) => [
                'id' => $a->id,
                'names' => $a->names,
                'color' => $a->color,
                'delivery_cost' => (float) $a->delivery_cost,
                'max_active_orders' => $a->max_active_orders,
                'active' => $active[$a->id] ?? 0,
                'full' => $a->max_active_orders !== null && ($active[$a->id] ?? 0) >= $a->max_active_orders,
                'cities' => $citiesByAgency[$a->id] ?? [],
            ])->values(),
            'cities' => $cities->map(function (City $city) use ($active, $pending) {
                $activeAgencies = $city->agencies->filter(fn ($a) => $a->pivot->is_active);
                $weights = $this->router->weights($activeAgencies);
                $total = array_sum($weights);

                return [
                    'id' => $city->id,
                    'name' => $city->name,
                    'delivery_cost_usd' => (float) $city->delivery_cost_usd,
                    'pending' => (int) ($pending[$city->id] ?? 0),
                    'agencies' => $city->agencies->map(fn ($a) => [
                        'id' => $a->id,
                        'names' => $a->names,
                        'color' => $a->color,
                        'weight' => $a->pivot->weight !== null ? (float) $a->pivot->weight : null,
                        'is_active' => (bool) $a->pivot->is_active,
                        'share' => $total > 0 && isset($weights[$a->id]) ? round($weights[$a->id] / $total, 4) : 0,
                        'active' => $active[$a->id] ?? 0,
                        'max_active_orders' => $a->max_active_orders,
                    ])->values(),
                ];
            })->values(),
        ];
    }

    private function ensureAgency(User $agency): void
    {
        abort_unless($agency->role?->description === 'Agencia', 404, 'Esa no es una agencia.');
    }
}
