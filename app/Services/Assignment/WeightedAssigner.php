<?php

namespace App\Services\Assignment;

use App\Constants\OrderStatus;
use App\Models\AssignmentPool;
use App\Models\Order;
use App\Models\SalesGroupMember;
use App\Models\Status;
use App\Models\User;
use App\Services\Assignment\Weighted\EffectiveWeights;
use App\Services\Assignment\Weighted\SmoothWeightedRoundRobin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reparto ponderado por grupos (fase 4). Une el roster del día con la base de datos:
 * saca a quien llegó a su máximo, arma los pesos del "modelo de porciones" y elige con el
 * Smooth Weighted Round Robin, guardando el saldo de cada tienda con bloqueo.
 *
 * Sin grupos, % ni máximos configurados, reparte parejo por turnos, igual que antes.
 */
class WeightedAssigner
{
    /** Estados que cuentan contra el máximo de una vendedora (Fran §23). */
    public const ACTIVE_STATUSES = [
        OrderStatus::ASIGNADO_VENDEDOR,
        OrderStatus::LLAMADO_1,
        OrderStatus::LLAMADO_2,
        OrderStatus::LLAMADO_3,
    ];

    public const STRATEGY = 'WeightedRoundRobin';

    private ?array $activeStatusIds = null;

    public static function poolFor(?int $shopId): string
    {
        return $shopId ? "shop:{$shopId}" : 'global';
    }

    /**
     * Elige vendedora entre las del roster.
     *
     * @return array{0: ?int, 1: array}  la elegida (null si no hay a quién darle) y el detalle para el registro.
     */
    public function pick(string $pool, Collection $agents): array
    {
        $ids = $agents->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($ids === []) {
            return [null, ['reason' => 'sin_roster']];
        }

        $available = $this->withCapacity($ids);
        if ($available === []) {
            return [null, ['reason' => 'todas_llenas']];
        }

        $weights = EffectiveWeights::compute(...$this->groupInfo($available));
        if ($weights === []) {
            return [null, ['reason' => 'sin_peso']];
        }

        return DB::transaction(function () use ($pool, $weights) {
            $row = $this->lockPool($pool);
            [$picked, $current] = SmoothWeightedRoundRobin::pick($weights, $row->state ?? []);
            $row->state = $current;
            $row->save();

            return [$picked, ['pool' => $pool, 'weights' => $this->shares($weights)]];
        });
    }

    /** Quita a las que llegaron a su máximo de órdenes activas (suma todas las tiendas). */
    public function withCapacity(array $ids): array
    {
        $max = User::whereIn('id', $ids)->whereNotNull('max_active_orders')->pluck('max_active_orders', 'id');
        if ($max->isEmpty()) {
            return $ids;
        }

        $counts = $this->activeCounts($max->keys()->all());

        return array_values(array_filter($ids, fn ($id) => !isset($max[$id]) || ($counts[$id] ?? 0) < $max[$id]));
    }

    /** @return array<int, int>  user_id => órdenes activas en todas las tiendas. */
    public function activeCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Order::whereIn('agent_id', $ids)
            ->whereIn('status_id', $this->activeStatusIds())
            ->groupBy('agent_id')
            ->selectRaw('agent_id, COUNT(*) as c')
            ->pluck('c', 'agent_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * Datos de grupo para EffectiveWeights.
     *
     * @return array{0: array<int, array{group: ?int, leader: bool, weight: ?float}>, 1: array<int, float>}
     */
    public function groupInfo(array $ids): array
    {
        $memberships = SalesGroupMember::open()
            ->whereIn('user_id', $ids)
            ->whereHas('group', fn ($q) => $q->where('is_active', true))
            ->with('group:id,leader_load')
            ->get()
            ->keyBy('user_id');

        $candidates = [];
        $leaderLoads = [];
        foreach ($ids as $id) {
            $m = $memberships->get($id);
            $candidates[$id] = [
                'group' => $m?->sales_group_id,
                'leader' => $m?->role === SalesGroupMember::ROLE_LEADER,
                'weight' => $m?->weight,
            ];
            if ($m) {
                $leaderLoads[$m->sales_group_id] = (float) $m->group->leader_load;
            }
        }

        return [$candidates, $leaderLoads];
    }

    /** Pesos efectivos normalizados (lo que debería recibir cada una, de 0 a 1). */
    public function targetShares(array $ids): array
    {
        return $this->shares(EffectiveWeights::compute(...$this->groupInfo($this->withCapacity($ids))));
    }

    /** Borra el saldo de una tienda, o de todas: el reparto vuelve a empezar desde cero. */
    public function reset(?string $pool = null): void
    {
        AssignmentPool::when($pool, fn ($q) => $q->where('key', $pool))->delete();
    }

    public function activeStatusIds(): array
    {
        return $this->activeStatusIds ??= Status::whereIn('description', self::ACTIVE_STATUSES)->pluck('id')->all();
    }

    private function lockPool(string $pool): AssignmentPool
    {
        AssignmentPool::query()->insertOrIgnore(['key' => $pool, 'created_at' => now(), 'updated_at' => now()]);

        return AssignmentPool::where('key', $pool)->lockForUpdate()->firstOrFail();
    }

    private function shares(array $weights): array
    {
        $total = array_sum($weights);

        return $total > 0 ? array_map(fn ($w) => round($w / $total, 4), $weights) : [];
    }
}
