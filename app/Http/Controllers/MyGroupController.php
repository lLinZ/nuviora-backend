<?php

namespace App\Http\Controllers;

use App\Models\BusinessDay;
use App\Models\DailyAgentRoster;
use App\Models\Log;
use App\Models\Order;
use App\Models\RosterChange;
use App\Models\SalesGroup;
use App\Models\SalesGroupMember;
use App\Models\Shop;
use App\Models\User;
use App\Services\Assignment\BulkReassignService;
use App\Services\Assignment\WeightedAssigner;
use App\Services\SalesGroups\GroupMetrics;
use App\Services\SalesGroups\GroupWeights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Mi grupo": lo que la Líder ve y hace con su grupo, además de todo lo de vendedora (Fran, 2026-09-24
 * y 2026-09-26). Cada acción valida aquí, en el servidor, que quien llama lidera un grupo activo y que
 * solo toca a personas de ese grupo.
 *
 * - Métricas de sus vendedoras y del grupo.
 * - Los % de sus vendedoras. El suyo lo fija solo el Admin.
 * - Quién está en el roster de hoy, con motivo al sacar a alguien.
 * - Reasignación en bloque dentro del grupo, nunca hacia ella misma (spec §5.1).
 */
class MyGroupController extends Controller
{
    public function __construct(private WeightedAssigner $assigner, private BulkReassignService $bulk)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json(['status' => true, 'data' => $this->payload($this->group())]);
    }

    /** GET ?start_date=Y-m-d&end_date=Y-m-d */
    public function metrics(Request $request, GroupMetrics $metrics): JsonResponse
    {
        $group = $this->group();
        $data = $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
        ]);
        if (Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) > 366) {
            throw ValidationException::withMessages(['end_date' => 'Elige un período de un año o menos.']);
        }

        $ids = $this->members($group)->keys()->all();
        $result = $metrics->period($ids, $data['start_date'], $data['end_date']);

        return response()->json([
            'status' => true,
            'data' => [
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'rows' => collect($result['rows'])->map(fn ($row, $userId) => ['user_id' => $userId] + $row)->values(),
                'totals' => $result['totals'],
            ],
        ]);
    }

    /** PUT { weights: [{ user_id, weight }] }: solo las vendedoras. El % de la Líder no se toca aquí. */
    public function weights(Request $request): JsonResponse
    {
        $group = $this->group();
        $data = $request->validate([
            'weights' => 'present|array',
            'weights.*.user_id' => 'required|integer',
            'weights.*.weight' => 'nullable|numeric|min:0|max:100',
        ]);

        $members = $this->members($group);
        $leader = $members->firstWhere('role', SalesGroupMember::ROLE_LEADER);
        $sellers = $members->where('role', SalesGroupMember::ROLE_SELLER);
        $weights = collect($data['weights'])->mapWithKeys(fn ($w) => [(int) $w['user_id'] => isset($w['weight']) ? round((float) $w['weight'], 2) : null]);

        if ($weights->has($leader->user_id)) {
            throw ValidationException::withMessages(['weights' => 'Tu % lo fija el administrador.']);
        }
        if ($weights->keys()->diff($sellers->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['weights' => 'Hay personas que no pertenecen a tu grupo.']);
        }
        GroupWeights::validate(
            true,
            $leader->weight,
            $sellers->keys()->mapWithKeys(fn ($id) => [$id => $weights->get($id)])->all(),
            'Para repartir con % primero el administrador tiene que fijar el tuyo.',
        );

        DB::transaction(function () use ($sellers, $weights) {
            foreach ($sellers as $userId => $membership) {
                $membership->update(['weight' => $weights->get($userId)]);
            }
        });

        $detail = $sellers->map(fn ($m, $id) => $this->name($m->user) . ' ' . ($weights->get($id) === null ? 'parejo' : GroupWeights::percent($weights->get($id))))->implode(', ');
        $this->log("La Líder {$this->name(Auth::user())} cambió los % de {$group->name}: {$detail}.");

        return response()->json(['status' => true, 'message' => 'Porcentajes guardados', 'data' => $this->payload($group)]);
    }

    /** PUT { shop_id, user_id, active, reason }: meter o sacar a alguien del roster de hoy de una tienda. */
    public function roster(Request $request): JsonResponse
    {
        $group = $this->group();
        $data = $request->validate([
            'shop_id' => 'required|integer|exists:shops,id',
            'user_id' => 'required|integer',
            'active' => 'required|boolean',
            'reason' => 'nullable|string|max:200|required_if:active,false,0',
        ], ['reason.required_if' => 'Escribe el motivo para sacarla del roster.']);

        $members = $this->members($group);
        $member = $members->get((int) $data['user_id']);
        if (!$member) {
            throw ValidationException::withMessages(['user_id' => 'Esa persona no pertenece a tu grupo.']);
        }
        $shopId = (int) $data['shop_id'];
        $active = (bool) $data['active'];
        $today = now()->toDateString();

        if ($active) {
            if (!$member->user->shops()->where('shops.id', $shopId)->exists()) {
                throw ValidationException::withMessages(['shop_id' => $this->name($member->user) . ' no trabaja en esa tienda.']);
            }
            if (!$this->openShopIds()->contains($shopId)) {
                throw ValidationException::withMessages(['shop_id' => 'Esa tienda no tiene la jornada abierta: el roster se arma al abrirla.']);
            }
        }

        DB::transaction(function () use ($shopId, $member, $active, $today, $data) {
            $row = DailyAgentRoster::where('date', $today)->where('shop_id', $shopId)->where('agent_id', $member->user_id)->first();
            if ($active) {
                DailyAgentRoster::updateOrCreate(
                    ['date' => $today, 'shop_id' => $shopId, 'agent_id' => $member->user_id],
                    ['is_active' => true],
                );
            } elseif ($row) {
                $row->update(['is_active' => false]);
            }
            RosterChange::create([
                'date' => $today,
                'shop_id' => $shopId,
                'agent_id' => $member->user_id,
                'is_active' => $active,
                'reason' => $active ? null : trim($data['reason']),
                'changed_by' => Auth::id(),
                'changed_by_role' => 'Líder',
            ]);
        });

        $who = $this->name($member->user);
        $shop = Shop::find($shopId)?->name;

        return response()->json([
            'status' => true,
            'message' => $active ? "{$who} entra al roster de hoy en {$shop}" : "{$who} sale del roster de hoy en {$shop}",
            'data' => $this->payload($group),
        ]);
    }

    /** GET ?from_agent_id=: estados con cuántas órdenes tiene y a quién se pueden pasar. */
    public function reassignPreview(Request $request): JsonResponse
    {
        $group = $this->group();
        $data = $request->validate(['from_agent_id' => 'required|integer']);
        $members = $this->members($group);
        $fromId = (int) $data['from_agent_id'];
        if (!$members->has($fromId)) {
            throw ValidationException::withMessages(['from_agent_id' => 'Esa persona no pertenece a tu grupo.']);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'statuses' => $this->bulk->preview($fromId),
                'group_mate_ids' => $this->targets($members)->keys()->reject(fn ($id) => $id === $fromId)->values(),
            ],
        ]);
    }

    /** POST { from_agent_id, to_agent_ids[], status_ids[] } */
    public function reassign(Request $request): JsonResponse
    {
        $group = $this->group();
        $allowed = $this->bulk->statuses()->pluck('id')->all();
        $data = $request->validate([
            'from_agent_id' => 'required|integer',
            'to_agent_ids' => 'required|array|min:1',
            'to_agent_ids.*' => 'integer|distinct',
            'status_ids' => 'required|array|min:1',
            'status_ids.*' => ['integer', Rule::in($allowed)],
        ]);

        $members = $this->members($group);
        $fromId = (int) $data['from_agent_id'];
        $targets = array_values(array_diff(array_map('intval', $data['to_agent_ids']), [$fromId]));

        if (!$members->has($fromId)) {
            throw ValidationException::withMessages(['from_agent_id' => 'Esa persona no pertenece a tu grupo.']);
        }
        if (in_array(Auth::id(), $targets, true)) {
            throw ValidationException::withMessages(['to_agent_ids' => 'No puedes pasarte órdenes a ti misma.']);
        }
        if ($targets === [] || array_diff($targets, $this->targets($members)->keys()->all()) !== []) {
            throw ValidationException::withMessages(['to_agent_ids' => 'Elige vendedoras de tu grupo, distintas de la de origen.']);
        }

        $result = $this->bulk->reassign($fromId, $targets, $data['status_ids'], Auth::id());
        $total = array_sum($result);
        $this->log("La Líder {$this->name(Auth::user())} pasó {$total} órdenes de {$this->name($members->get($fromId)->user)} a otras vendedoras de {$group->name}.");

        return response()->json([
            'status' => true,
            'message' => $total === 1 ? 'Se reasignó 1 orden' : "Se reasignaron {$total} órdenes",
            'data' => ['moved' => $result, 'total' => $total],
        ]);
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function group(): SalesGroup
    {
        $group = Auth::user()->ledGroup();
        abort_unless($group, 403, 'Esta sección es solo para la Líder de un grupo de venta.');

        return $group;
    }

    /** Integrantes vigentes (Líder y vendedoras), por user_id. */
    private function members(SalesGroup $group): Collection
    {
        return $group->openMembers()
            ->with('user:id,names,surnames,max_active_orders')
            ->get()
            ->keyBy('user_id');
    }

    /** A quién se le pueden pasar órdenes: las vendedoras del grupo, nunca la Líder. */
    private function targets(Collection $members): Collection
    {
        return $members->where('role', SalesGroupMember::ROLE_SELLER);
    }

    private function openShopIds(): Collection
    {
        return BusinessDay::where('date', now()->toDateString())
            ->whereNotNull('open_at')
            ->whereNull('close_at')
            ->pluck('shop_id');
    }

    private function payload(SalesGroup $group): array
    {
        $members = $this->members($group);
        $ids = $members->keys()->all();
        $statuses = $this->bulk->statuses();
        $active = $this->assigner->activeCounts($ids);

        $pipeline = Order::whereIn('agent_id', $ids)
            ->whereIn('status_id', $statuses->pluck('id'))
            ->groupBy('agent_id', 'status_id')
            ->selectRaw('agent_id, status_id, COUNT(*) as c')
            ->get()
            ->groupBy('agent_id');

        $today = now()->toDateString();
        $linked = DB::table('shop_user')->whereIn('user_id', $ids)->get()->groupBy('shop_id');
        $roster = DailyAgentRoster::where('date', $today)->whereIn('agent_id', $ids)->get()->groupBy('shop_id');
        $changes = RosterChange::with('changedBy:id,names,surnames')
            ->where('date', $today)
            ->whereIn('agent_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($c) => $c->shop_id . ':' . $c->agent_id);
        $openShops = $this->openShopIds();

        $ordered = $members->sortBy(fn ($m) => ($m->role === SalesGroupMember::ROLE_LEADER ? '0' : '1') . $this->name($m->user));

        return [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'leader_commission_pct' => $group->leader_commission_pct,
            ],
            'me' => Auth::id(),
            'statuses' => $statuses->map(fn ($s) => ['id' => $s->id, 'description' => $s->description])->values(),
            'members' => $ordered->map(fn (SalesGroupMember $m) => [
                'id' => $m->user_id,
                'name' => $this->name($m->user),
                'is_leader' => $m->role === SalesGroupMember::ROLE_LEADER,
                'weight' => $m->weight,
                'max_active_orders' => $m->user->max_active_orders,
                'active_orders' => $active[$m->user_id] ?? 0,
                'pipeline' => $pipeline->get($m->user_id, collect())->pluck('c', 'status_id')->map(fn ($c) => (int) $c),
            ])->values(),
            'shops' => Shop::orderBy('id')->get(['id', 'name'])->map(fn (Shop $shop) => [
                'id' => $shop->id,
                'name' => $shop->name,
                'is_open' => $openShops->contains($shop->id),
                'members' => $ordered->map(function (SalesGroupMember $m) use ($shop, $linked, $roster, $changes) {
                    $last = $changes->get($shop->id . ':' . $m->user_id)?->last();

                    return [
                        'user_id' => $m->user_id,
                        'linked' => $linked->get($shop->id, collect())->contains('user_id', $m->user_id),
                        'in_roster' => (bool) $roster->get($shop->id, collect())->firstWhere('agent_id', $m->user_id)?->is_active,
                        'last_change' => $last ? [
                            'active' => $last->is_active,
                            'reason' => $last->reason,
                            'by' => $this->name($last->changedBy),
                            'at' => $last->created_at?->format('H:i'),
                        ] : null,
                    ];
                })->values(),
            ])->values(),
        ];
    }

    private function log(string $description): void
    {
        $log = Log::create(['description' => $description, 'impact' => 'Normal', 'author' => $this->name(Auth::user())]);
        $log->user()->associate(Auth::user());
        $log->save();
    }

    private function name(?User $user): string
    {
        return trim(($user?->names ?? '') . ' ' . ($user?->surnames ?? ''));
    }
}
