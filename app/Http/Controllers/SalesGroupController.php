<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\SalesGroup;
use App\Models\SalesGroupMember;
use App\Models\User;
use App\Services\Assignment\WeightedAssigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grupos de ventas (fase 4). Solo el Admin los arma: crea el grupo, elige la Líder y las vendedoras
 * (Fran, segunda ronda §2). Cada persona está en un solo grupo a la vez (§3) y cada cambio cierra
 * la membresía anterior en lugar de borrarla, para no perder el historial.
 */
class SalesGroupController extends Controller
{
    public function __construct(private WeightedAssigner $assigner)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['status' => true, 'data' => $this->payload()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateGroup($request);

        DB::transaction(function () use ($data) {
            $group = SalesGroup::create([
                'name' => $data['name'],
                'leader_commission_pct' => $data['leader_commission_pct'] ?? 0,
                'created_by' => Auth::id(),
            ]);
            $this->setLeader($group, $data['leader_id'] ?? null);
        });

        return response()->json(['status' => true, 'message' => 'Grupo creado', 'data' => $this->payload()]);
    }

    public function update(Request $request, SalesGroup $salesGroup): JsonResponse
    {
        $data = $this->validateGroup($request);

        DB::transaction(function () use ($salesGroup, $data) {
            $salesGroup->update([
                'name' => $data['name'],
                'leader_commission_pct' => $data['leader_commission_pct'] ?? $salesGroup->leader_commission_pct,
            ]);
            $this->setLeader($salesGroup, $data['leader_id'] ?? null);
        });

        return response()->json(['status' => true, 'message' => 'Grupo actualizado', 'data' => $this->payload()]);
    }

    /** Archiva el grupo: sus integrantes quedan sin grupo y el historial se conserva. */
    public function destroy(SalesGroup $salesGroup): JsonResponse
    {
        DB::transaction(function () use ($salesGroup) {
            $salesGroup->openMembers()->get()->each->close(Auth::id());
            $salesGroup->update(['is_active' => false]);
        });

        return response()->json(['status' => true, 'message' => 'Grupo eliminado', 'data' => $this->payload()]);
    }

    /** PUT { user_ids: [] }: define las vendedoras del grupo (sin la Líder). */
    public function members(Request $request, SalesGroup $salesGroup): JsonResponse
    {
        $this->ensureActive($salesGroup);
        $data = $request->validate([
            'user_ids' => 'present|array',
            'user_ids.*' => 'integer|exists:users,id',
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['user_ids'])));
        $this->ensureSellers($ids);

        $leaderId = $this->leaderId($salesGroup);
        if ($leaderId && in_array($leaderId, $ids, true)) {
            throw ValidationException::withMessages(['user_ids' => 'La Líder ya pertenece al grupo; no se agrega como vendedora.']);
        }

        $moved = [];
        DB::transaction(function () use ($salesGroup, $ids, &$moved) {
            $current = $salesGroup->openMembers()->where('role', SalesGroupMember::ROLE_SELLER)->get()->keyBy('user_id');

            foreach ($current as $userId => $membership) {
                if (!in_array($userId, $ids, true)) {
                    $membership->close(Auth::id());
                }
            }

            foreach ($ids as $userId) {
                if ($current->has($userId)) {
                    continue;
                }
                $elsewhere = SalesGroupMember::open()->where('user_id', $userId)->with('group:id,name,is_active', 'user:id,names,surnames')->first();
                if ($elsewhere) {
                    if ($elsewhere->role === SalesGroupMember::ROLE_LEADER && $elsewhere->group?->is_active) {
                        throw ValidationException::withMessages([
                            'user_ids' => $this->name($elsewhere->user) . " es la Líder del grupo {$elsewhere->group->name}.",
                        ]);
                    }
                    $elsewhere->close(Auth::id());
                    $moved[] = $this->name($elsewhere->user) . " (antes en {$elsewhere->group->name})";
                }
                $this->open($salesGroup, $userId, SalesGroupMember::ROLE_SELLER);
            }
        });

        $message = $moved ? 'Vendedoras actualizadas. Se movieron: ' . implode(', ', $moved) : 'Vendedoras actualizadas';

        return response()->json(['status' => true, 'message' => $message, 'data' => $this->payload()]);
    }

    /**
     * PUT { weights: [{ user_id, weight }] }: % de reparto dentro del grupo, con la Líder incluida.
     * Su % lo pone solo el Admin (Fran, 2026-09-26). Tres formas válidas:
     * - todo vacío: parejo, la Líder recibe como una vendedora;
     * - solo la Líder con %: las vendedoras se reparten parejo lo que queda;
     * - todas con %: tienen que sumar 100.
     */
    public function weights(Request $request, SalesGroup $salesGroup): JsonResponse
    {
        $this->ensureActive($salesGroup);
        $data = $request->validate([
            'weights' => 'present|array',
            'weights.*.user_id' => 'required|integer',
            'weights.*.weight' => 'nullable|numeric|min:0|max:100',
        ]);

        $members = $salesGroup->openMembers()->with('user:id,names,surnames')->get()->keyBy('user_id');
        $weights = collect($data['weights'])->mapWithKeys(fn ($w) => [(int) $w['user_id'] => isset($w['weight']) ? round((float) $w['weight'], 2) : null]);

        if ($weights->keys()->diff($members->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['weights' => 'Hay personas que no pertenecen a este grupo.']);
        }

        $leader = $members->firstWhere('role', SalesGroupMember::ROLE_LEADER);
        $leaderPct = $leader ? $weights->get($leader->user_id) : null;
        $sellers = $members->where('role', SalesGroupMember::ROLE_SELLER)->keys()->map(fn ($id) => $weights->get($id));
        $filled = $sellers->filter(fn ($w) => $w !== null);

        if ($filled->isNotEmpty() && $filled->count() !== $sellers->count()) {
            throw ValidationException::withMessages(['weights' => 'Pon el % de todas las vendedoras, o déjalas todas vacías para que se repartan parejo.']);
        }
        if ($filled->isNotEmpty()) {
            if ($leader && $leaderPct === null) {
                throw ValidationException::withMessages(['weights' => 'Falta el % de la Líder, ' . $this->name($leader->user) . '.']);
            }
            $total = round($filled->sum() + ($leaderPct ?? 0), 2);
            if (abs($total - 100) > 0.01) {
                $diff = $this->number(abs(100 - $total));
                throw ValidationException::withMessages([
                    'weights' => 'Los % del grupo tienen que sumar 100. Ahora suman ' . $this->number($total) . ($total > 100 ? ", sobran {$diff}." : ", faltan {$diff}."),
                ]);
            }
        } elseif ($sellers->isNotEmpty() && $leaderPct !== null && $leaderPct >= 100) {
            throw ValidationException::withMessages(['weights' => 'Si la Líder recibe el 100 %, pon 0 % a las vendedoras.']);
        }

        DB::transaction(function () use ($members, $weights) {
            foreach ($members as $userId => $membership) {
                $membership->update(['weight' => $weights->get($userId)]);
            }
        });

        return response()->json(['status' => true, 'message' => 'Porcentajes guardados', 'data' => $this->payload()]);
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function payload(): array
    {
        $groups = SalesGroup::active()->with(['openMembers.user:id,names,surnames'])->orderBy('name')->get();
        $sellers = User::where('role_id', $this->sellerRoleId())
            ->with('salesGroupMembership')
            ->orderBy('names')
            ->get(['id', 'names', 'surnames', 'max_active_orders']);
        $active = $this->assigner->activeCounts($sellers->pluck('id')->all());

        return [
            'groups' => $groups->map(function (SalesGroup $g) {
                $leader = $g->openMembers->firstWhere('role', SalesGroupMember::ROLE_LEADER);
                $members = $g->openMembers->where('role', SalesGroupMember::ROLE_SELLER);

                return [
                    'id' => $g->id,
                    'name' => $g->name,
                    'leader_commission_pct' => $g->leader_commission_pct,
                    'leader' => $leader ? ['id' => $leader->user_id, 'name' => $this->name($leader->user), 'weight' => $leader->weight] : null,
                    'members' => $members->map(fn ($m) => [
                        'user_id' => $m->user_id,
                        'name' => $this->name($m->user),
                        'weight' => $m->weight,
                        'since' => $m->started_at?->toDateString(),
                    ])->sortBy('name')->values(),
                ];
            })->values(),
            'sellers' => $sellers->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $this->name($u),
                'max_active_orders' => $u->max_active_orders,
                'active_orders' => $active[$u->id] ?? 0,
                'group_id' => $u->salesGroupMembership?->sales_group_id,
                'is_leader' => $u->salesGroupMembership?->role === SalesGroupMember::ROLE_LEADER,
            ])->values(),
        ];
    }

    private function validateGroup(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'leader_id' => 'nullable|integer|exists:users,id',
            'leader_commission_pct' => 'nullable|numeric|min:0|max:100',
        ]);
        if (!empty($data['leader_id'])) {
            $this->ensureSellers([(int) $data['leader_id']]);
        }

        return $data;
    }

    /**
     * Cambia la Líder: cierra la anterior y, si la nueva estaba como vendedora en algún grupo, la saca de ahí.
     * La nueva hereda el % de la anterior, así la lista del grupo sigue sumando lo mismo.
     */
    private function setLeader(SalesGroup $group, ?int $userId): void
    {
        $current = $group->openMembers()->where('role', SalesGroupMember::ROLE_LEADER)->first();
        if ($current && (int) $current->user_id === (int) $userId) {
            return;
        }
        $current?->close(Auth::id());

        if (!$userId) {
            return;
        }

        $elsewhere = SalesGroupMember::open()->where('user_id', $userId)->with('group:id,name,is_active', 'user:id,names,surnames')->first();
        if ($elsewhere?->role === SalesGroupMember::ROLE_LEADER && $elsewhere->group?->is_active) {
            throw ValidationException::withMessages([
                'leader_id' => $this->name($elsewhere->user) . " ya es la Líder del grupo {$elsewhere->group->name}.",
            ]);
        }
        $elsewhere?->close(Auth::id());
        $this->open($group, $userId, SalesGroupMember::ROLE_LEADER, $current?->weight);
    }

    private function open(SalesGroup $group, int $userId, string $role, ?float $weight = null): void
    {
        SalesGroupMember::create([
            'sales_group_id' => $group->id,
            'user_id' => $userId,
            'role' => $role,
            'weight' => $weight,
            'started_at' => now(),
            'added_by' => Auth::id(),
        ]);
    }

    private function leaderId(SalesGroup $group): ?int
    {
        return $group->openMembers()->where('role', SalesGroupMember::ROLE_LEADER)->value('user_id');
    }

    private function ensureSellers(array $ids): void
    {
        $valid = User::whereIn('id', $ids)->where('role_id', $this->sellerRoleId())->count();
        if ($valid !== count($ids)) {
            throw ValidationException::withMessages(['user_ids' => 'Solo se pueden agregar usuarios con rol Vendedor.']);
        }
    }

    private function ensureActive(SalesGroup $group): void
    {
        abort_unless($group->is_active, 404, 'El grupo no existe');
    }

    private function sellerRoleId(): ?int
    {
        return Role::where('description', 'Vendedor')->value('id');
    }

    /** 120 → "120 %", 99.5 → "99,5 %". */
    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') . ' %';
    }

    private function name(?User $user): string
    {
        return trim(($user?->names ?? '') . ' ' . ($user?->surnames ?? ''));
    }
}
