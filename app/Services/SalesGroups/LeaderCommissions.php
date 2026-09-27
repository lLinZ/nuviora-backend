<?php

namespace App\Services\SalesGroups;

use App\Models\Earning;
use App\Models\LeaderCommission;
use App\Models\SalesGroupMember;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Comisión de liderazgo (Fran, segunda ronda §7; spec de la Líder §12).
 *
 * Nace cuando se genera la comisión de una vendedora (venta o upsell, la lógica de siempre): la Líder
 * de su grupo en ese momento recibe su % de ese monto. Se guardan la base, el % y el monto, así que
 * si el Admin cambia el %, vale solo hacia adelante (§12.5). Las comisiones de la propia Líder no
 * cuentan (§12.3). Si la comisión de la vendedora se borra, la de la Líder se borra con ella (llave
 * foránea en cascada), también cuando se borra en bloque sin pasar por el modelo.
 */
final class LeaderCommissions
{
    public const BASE_TYPES = ['vendedor', 'upsell'];

    public function forEarning(Earning $earning): ?LeaderCommission
    {
        if (!in_array($earning->role_type, self::BASE_TYPES, true) || (float) $earning->amount_usd <= 0) {
            return null;
        }

        $membership = SalesGroupMember::open()
            ->where('user_id', $earning->user_id)
            ->where('role', SalesGroupMember::ROLE_SELLER)
            ->whereHas('group', fn ($q) => $q->where('is_active', true))
            ->with('group')
            ->first();
        if (!$membership || (float) $membership->group->leader_commission_pct <= 0) {
            return null;
        }

        $leaderId = $membership->group->openMembers()->where('role', SalesGroupMember::ROLE_LEADER)->value('user_id');
        if (!$leaderId) {
            return null;
        }

        $pct = (float) $membership->group->leader_commission_pct;

        return LeaderCommission::firstOrCreate(['earning_id' => $earning->id], [
            'order_id' => $earning->order_id,
            'seller_id' => $earning->user_id,
            'leader_id' => $leaderId,
            'sales_group_id' => $membership->sales_group_id,
            'base_usd' => (float) $earning->amount_usd,
            'pct' => $pct,
            'amount_usd' => round((float) $earning->amount_usd * $pct / 100, 2),
            'earning_date' => $earning->earning_date,
        ]);
    }

    /**
     * Las tres cifras de la Líder (§12.2) y el desglose por vendedora (§12.4) en un período.
     *
     * @return array{personal: array, leadership: array, total: float}
     */
    public function forLeader(int $leaderId, string $start, string $end): array
    {
        $personal = DB::table('earnings')
            ->where('user_id', $leaderId)
            ->whereIn('role_type', self::BASE_TYPES)
            ->whereBetween('earning_date', [$start, $end])
            ->groupBy('role_type')
            ->selectRaw('role_type, SUM(amount_usd) as usd')
            ->get()
            ->pluck('usd', 'role_type');
        $sales = round((float) ($personal['vendedor'] ?? 0), 2);
        $upsells = round((float) ($personal['upsell'] ?? 0), 2);

        $rows = LeaderCommission::with('seller:id,names,surnames')
            ->where('leader_id', $leaderId)
            ->whereBetween('earning_date', [$start, $end])
            ->get()
            ->groupBy('seller_id')
            ->map(function (Collection $items) {
                $seller = $items->first()->seller;
                $pcts = $items->pluck('pct')->unique()->values();

                return [
                    'seller_id' => $items->first()->seller_id,
                    'name' => trim(($seller?->names ?? '') . ' ' . ($seller?->surnames ?? '')),
                    'base_usd' => round($items->sum('base_usd'), 2),
                    // Si el Admin cambió el % dentro del período, se muestran los dos.
                    'pcts' => $pcts->all(),
                    'amount_usd' => round($items->sum('amount_usd'), 2),
                ];
            })
            ->sortByDesc('amount_usd')
            ->values();

        $leadership = round($rows->sum('amount_usd'), 2);

        return [
            'personal' => ['sales' => $sales, 'upsells' => $upsells, 'total' => round($sales + $upsells, 2)],
            'leadership' => [
                'total' => $leadership,
                'base_total' => round($rows->sum('base_usd'), 2),
                'by_seller' => $rows->all(),
            ],
            'total' => round($sales + $upsells + $leadership, 2),
        ];
    }

    /** Para "Ganancias globales": cuánto hay que pagarle a cada Líder por liderazgo en el período. */
    public function byLeader(string $start, string $end): Collection
    {
        return LeaderCommission::with(['leader.role', 'group:id,name'])
            ->whereBetween('earning_date', [$start, $end])
            ->get()
            ->groupBy('leader_id')
            ->map(function (Collection $items) {
                /** @var User $leader */
                $leader = $items->first()->leader;

                return [
                    'user_id' => $leader->id,
                    'names' => $leader->names,
                    'surnames' => $leader->surnames,
                    'email' => $leader->email,
                    'color' => $leader->color,
                    'groups' => $items->pluck('group.name')->unique()->values()->all(),
                    'sellers_count' => $items->pluck('seller_id')->unique()->count(),
                    'base_usd' => round($items->sum('base_usd'), 2),
                    'amount_usd' => round($items->sum('amount_usd'), 2),
                ];
            })
            ->sortByDesc('amount_usd')
            ->values();
    }
}
