<?php

namespace App\Http\Controllers;

use App\Models\BusinessDay;
use App\Models\DailyAgentRoster;
use App\Models\Log;
use App\Models\MeetingRecord;
use App\Models\Order;
use App\Models\RosterChange;
use App\Models\SalesGroup;
use App\Models\SalesGroupMember;
use App\Models\SellerNote;
use App\Models\Shop;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Services\Assignment\BulkReassignService;
use App\Services\Assignment\WeightedAssigner;
use App\Services\SalesGroups\GroupMetrics;
use App\Services\SalesGroups\GroupWeights;
use App\Services\SalesGroups\LeaderCommissions;
use App\Services\SalesGroups\SaturationMonitor;
use App\Services\SalesGroups\WeeklyReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        return response()->json(['status' => true, 'data' => $this->payload($this->group(true))]);
    }

    /** GET ?start_date=Y-m-d&end_date=Y-m-d */
    /**
     * GET ?start_date=&end_date= y, para comparar (spec §7.2), ?compare_start=&compare_end=: el mismo
     * cálculo sobre el otro período, para mostrar cuánto subió o bajó cada número.
     */
    public function metrics(Request $request, GroupMetrics $metrics, LeaderCommissions $commissions): JsonResponse
    {
        $group = $this->group(true);
        $data = $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'compare_start' => 'nullable|required_with:compare_end|date_format:Y-m-d',
            'compare_end' => 'nullable|required_with:compare_start|date_format:Y-m-d|after_or_equal:compare_start',
        ]);
        foreach ([['start_date', 'end_date'], ['compare_start', 'compare_end']] as [$from, $to]) {
            if (!empty($data[$from]) && Carbon::parse($data[$from])->diffInDays(Carbon::parse($data[$to])) > 366) {
                throw ValidationException::withMessages([$to => 'Elige un período de un año o menos.']);
            }
        }

        $ids = $this->members($group)->keys()->all();
        $rows = fn (array $result) => collect($result['rows'])->map(fn ($row, $userId) => ['user_id' => $userId] + $row)->values();
        $result = $metrics->period($ids, $data['start_date'], $data['end_date']);

        $compare = null;
        if (!empty($data['compare_start'])) {
            $other = $metrics->period($ids, $data['compare_start'], $data['compare_end']);
            $compare = [
                'start_date' => $data['compare_start'],
                'end_date' => $data['compare_end'],
                'rows' => $rows($other),
                'totals' => $other['totals'],
            ];
        }

        return response()->json([
            'status' => true,
            'data' => [
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'rows' => $rows($result),
                'totals' => $result['totals'],
                'compare' => $compare,
                // Sus ganancias: como vendedora, por liderazgo y el total (spec §12.2 y §12.4)
                'earnings' => $this->leaderId($group)
                    ? $commissions->forLeader($this->leaderId($group), $data['start_date'], $data['end_date'])
                    : null,
            ],
        ]);
    }

    /** GET ?start_date=&end_date=: cada agencia, solo con los pedidos del grupo (spec §10). */
    public function agencies(Request $request, GroupMetrics $metrics): JsonResponse
    {
        $group = $this->group(true);
        $data = $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
        ]);
        if (Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) > 366) {
            throw ValidationException::withMessages(['end_date' => 'Elige un período de un año o menos.']);
        }

        return response()->json([
            'status' => true,
            'data' => $metrics->agencies($this->members($group)->keys()->all(), $data['start_date'], $data['end_date']),
        ]);
    }

    /** GET ?seller_id=: notas privadas de la Líder sobre sus vendedoras, escritas en este grupo (spec §13). */
    public function notes(Request $request): JsonResponse
    {
        $group = $this->group(true);
        $data = $request->validate(['seller_id' => 'nullable|integer']);

        $notes = SellerNote::with(['author:id,names,surnames', 'group:id,name'])
            ->where('sales_group_id', $group->id)
            ->when($data['seller_id'] ?? null, fn ($q, $id) => $q->where('seller_id', $id))
            ->latest('id')
            ->get();

        return response()->json(['status' => true, 'data' => $notes->map->toPayload()->values()]);
    }

    /** POST { seller_id, body }: nueva nota. Solo sobre vendedoras de su grupo; no se editan ni se borran. */
    public function storeNote(Request $request): JsonResponse
    {
        $group = $this->group();
        $data = $request->validate([
            'seller_id' => 'required|integer',
            'body' => 'required|string|max:2000',
        ]);
        $member = $this->members($group)->get((int) $data['seller_id']);
        if (!$member || $member->role !== SalesGroupMember::ROLE_SELLER) {
            throw ValidationException::withMessages(['seller_id' => 'Solo puedes anotar sobre las vendedoras de tu grupo.']);
        }

        $note = SellerNote::create([
            'sales_group_id' => $group->id,
            'seller_id' => $member->user_id,
            'author_id' => Auth::id(),
            'body' => trim($data['body']),
        ]);

        return response()->json(['status' => true, 'message' => 'Nota guardada', 'data' => $note->load('author:id,names,surnames', 'group:id,name')->toPayload()]);
    }

    /** GET ?week=Y-m-d: el reporte semanal del grupo (spec §16), con lo automático y lo que escribió la Líder. */
    public function report(Request $request, WeeklyReportBuilder $builder): JsonResponse
    {
        $group = $this->group(true);
        $data = $request->validate(['week' => 'nullable|date_format:Y-m-d']);
        $week = Carbon::parse($data['week'] ?? now()->toDateString());

        return response()->json(['status' => true, 'data' => $builder->build($group, $week)]);
    }

    /** PUT { week, problems, actions, ... }: guarda lo que escribe la Líder para esa semana. */
    public function saveReport(Request $request, WeeklyReportBuilder $builder): JsonResponse
    {
        $group = $this->group();
        $rules = ['week' => 'required|date_format:Y-m-d'];
        foreach (array_keys(WeeklyReport::FIELDS) as $field) {
            $rules[$field] = 'nullable|string|max:5000';
        }
        $data = $request->validate($rules);
        $week = Carbon::parse($data['week'])->startOfWeek(Carbon::MONDAY);

        WeeklyReport::updateOrCreate(
            ['sales_group_id' => $group->id, 'week_start' => $week->toDateString()],
            collect(WeeklyReport::FIELDS)->keys()->mapWithKeys(fn ($f) => [$f => $data[$f] ?? null])->all() + ['updated_by' => Auth::id()],
        );

        return response()->json(['status' => true, 'message' => 'Reporte guardado', 'data' => $builder->build($group, $week)]);
    }

    /** GET: grabaciones y archivos de reuniones del grupo (spec §14). */
    public function meetings(): JsonResponse
    {
        $group = $this->group(true);
        $records = MeetingRecord::with(['sellers:id,names,surnames', 'author:id,names,surnames'])
            ->where('sales_group_id', $group->id)
            ->orderByDesc('meeting_date')->orderByDesc('id')
            ->get();

        return response()->json(['status' => true, 'data' => $records->map->toPayload()->values()]);
    }

    /**
     * POST (multipart) { meeting_type, meeting_date, title, notes?, seller_ids[], file? | url?, consent }:
     * archivo (hasta 15 MB, al disco privado) o enlace. Si es una grabación, hay que confirmar el
     * consentimiento de las personas grabadas.
     */
    public function storeMeeting(Request $request): JsonResponse
    {
        $group = $this->group();
        $data = $request->validate([
            'meeting_type' => ['required', Rule::in(array_keys(MeetingRecord::TYPES))],
            'meeting_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'title' => 'required|string|max:150',
            'notes' => 'nullable|string|max:2000',
            'seller_ids' => 'required|array|min:1',
            'seller_ids.*' => 'integer|distinct',
            'file' => 'nullable|file|max:15360|mimes:mp3,m4a,wav,ogg,oga,opus,mp4,mov,webm,pdf,jpg,jpeg,png,doc,docx',
            'url' => 'nullable|url|max:500',
            'consent' => 'accepted',
        ], [
            'consent.accepted' => 'Confirma que las personas de la reunión saben que quedó registrada y están de acuerdo.',
            'file.max' => 'El archivo pasa de 15 MB. Súbelo a Drive y pega el enlace.',
        ]);
        if (!$request->hasFile('file') && empty($data['url'])) {
            throw ValidationException::withMessages(['file' => 'Adjunta un archivo o pega un enlace.']);
        }
        $sellers = $this->members($group)->where('role', SalesGroupMember::ROLE_SELLER)->keys()->all();
        if (array_diff(array_map('intval', $data['seller_ids']), $sellers) !== []) {
            throw ValidationException::withMessages(['seller_ids' => 'Elige vendedoras de tu grupo.']);
        }

        $file = $request->file('file');
        $path = $file?->store("reuniones/{$group->id}", 'local');
        try {
            $record = DB::transaction(function () use ($group, $data, $file, $path) {
                $record = MeetingRecord::create([
                    'sales_group_id' => $group->id,
                    'created_by' => Auth::id(),
                    'meeting_type' => $data['meeting_type'],
                    'meeting_date' => $data['meeting_date'],
                    'title' => trim($data['title']),
                    'notes' => $data['notes'] ?? null,
                    'file_path' => $path,
                    'file_name' => $file?->getClientOriginalName(),
                    'url' => $data['url'] ?? null,
                    'consent_confirmed' => true,
                ]);
                $record->sellers()->sync(array_map('intval', $data['seller_ids']));

                return $record;
            });
        } catch (\Throwable $e) {
            // Si no se pudo guardar, el archivo no queda huérfano en el disco
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return response()->json([
            'status' => true,
            'message' => 'Reunión guardada',
            'data' => $record->load(['sellers:id,names,surnames', 'author:id,names,surnames'])->toPayload(),
        ]);
    }

    /** GET: descarga el archivo de una reunión (la Líder de ese grupo o el Admin). */
    public function meetingFile(MeetingRecord $meeting)
    {
        $group = $this->group(true);
        abort_unless($meeting->sales_group_id === $group->id && $meeting->file_path, 404);
        abort_unless(Storage::disk('local')->exists($meeting->file_path), 404, 'El archivo ya no está.');

        return Storage::disk('local')->download($meeting->file_path, $meeting->file_name ?? basename($meeting->file_path));
    }

    /** DELETE: solo el Admin borra una reunión y su archivo (políticas de retención, spec §14). */
    public function destroyMeeting(MeetingRecord $meeting): JsonResponse
    {
        abort_unless($this->isAdmin(), 403, 'Solo el administrador puede borrar grabaciones.');
        if ($meeting->file_path) {
            Storage::disk('local')->delete($meeting->file_path);
        }
        $meeting->delete();

        return response()->json(['status' => true, 'message' => 'Reunión borrada']);
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

    /**
     * El grupo de quien llama. Con $adminCanView, el Admin (o Master) puede mirar el de cualquier Líder
     * con ?group_id= (spec §18: analizar una Líder y ver su pantalla). Es solo para mirar: las acciones
     * (%, roster, reasignar, notas) siguen exigiendo ser la Líder, para que nada quede hecho en su nombre.
     */
    private function group(bool $adminCanView = false): SalesGroup
    {
        $user = Auth::user();
        if ($adminCanView && request()->filled('group_id') && $this->isAdmin()) {
            return SalesGroup::active()->findOrFail((int) request('group_id'));
        }
        $group = $user->ledGroup();
        abort_unless($group, 403, 'Esta sección es solo para la Líder de un grupo de venta.');

        return $group;
    }

    private function isAdmin(): bool
    {
        return in_array(Auth::user()->role?->description, ['Admin', 'Master'], true);
    }

    private function leaderId(SalesGroup $group): ?int
    {
        $id = $group->openMembers()->where('role', SalesGroupMember::ROLE_LEADER)->value('user_id');

        return $id ? (int) $id : null;
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
        $saturation = app(SaturationMonitor::class)->groupStatus($group);

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
            // La Líder del grupo (quien no recibe sus propias reasignaciones). Si mira el Admin, solo lectura.
            'me' => $this->leaderId($group) ?? Auth::id(),
            'read_only' => !(Auth::user()->ledGroup()?->id === $group->id),
            'statuses' => $statuses->map(fn ($s) => ['id' => $s->id, 'description' => $s->description])->values(),
            // Carga activa (Asignado a vendedor + Reprogramado para hoy) y alerta de saturación (spec §9)
            'saturation' => [
                'average' => $saturation['average'],
                'threshold' => SaturationMonitor::THRESHOLD,
                'min_load' => SaturationMonitor::MIN_LOAD,
            ],
            'members' => $ordered->map(fn (SalesGroupMember $m) => [
                'id' => $m->user_id,
                'name' => $this->name($m->user),
                'is_leader' => $m->role === SalesGroupMember::ROLE_LEADER,
                'weight' => $m->weight,
                'max_active_orders' => $m->user->max_active_orders,
                'active_orders' => $active[$m->user_id] ?? 0,
                'pipeline' => $pipeline->get($m->user_id, collect())->pluck('c', 'status_id')->map(fn ($c) => (int) $c),
                'load' => $saturation['members'][$m->user_id]['load'] ?? 0,
                'saturated' => $saturation['members'][$m->user_id]['saturated'] ?? false,
                'over_pct' => $saturation['members'][$m->user_id]['over_pct'] ?? null,
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
