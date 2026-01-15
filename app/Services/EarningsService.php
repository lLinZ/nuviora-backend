<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Status;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class EarningsService
{
    // Comisiones en USD
    private float $vendorCommission   = 1.0;   // vendedora por orden completada
    private float $delivererCommission = 2.5;  // repartidor por orden entregada
    private float $managerCommission   = 0.5;  // gerente por venta exitosa

    public function __construct() {}

    /**
     * Devuelve resumen de ganancias por rol, por rango de fechas [from, to]
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        // Seleccionamos la tasa BCV por defecto para estas comisiones
        $rate = (float) (Setting::get('rate_bcv_usd', 1) ?? 1);

        // IDs de status importantes
        $statusCompletedId = Status::where('description', 'Entregado')->value('id'); // Las vendedoras ganan cuando se entrega
        $statusDeliveredId = Status::where('description', 'Entregado')->value('id');

        // ====== VENDEDORAS (1 USD por orden completada) ======
        $vendorRoleId = Role::where('description', 'Vendedor')->value('id');

        $vendorRows = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status_id', $statusCompletedId)
            ->whereNotNull('agent_id')
            ->selectRaw('agent_id, COUNT(*) as orders_count')
            ->groupBy('agent_id')
            ->get();

        $vendors = $this->mapUserEarnings(
            $vendorRows,
            'agent_id',
            $vendorRoleId,
            $this->vendorCommission,
            $rate
        );

        // ====== REPARTIDORES (2.5 USD por orden entregada) ======
        $delivererRoleId = Role::where('description', 'Repartidor')->value('id');

        $delivererRows = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status_id', $statusDeliveredId)
            ->whereNotNull('deliverer_id')
            ->selectRaw('deliverer_id, COUNT(*) as orders_count')
            ->groupBy('deliverer_id')
            ->get();

        $deliverers = $this->mapUserEarnings(
            $delivererRows,
            'deliverer_id',
            $delivererRoleId,
            $this->delivererCommission,
            $rate
        );

        // ====== GERENTES (0.5 USD por venta exitosa) ======
        $managerRoleId = Role::where('description', 'Gerente')->value('id');

        // Total de órdenes exitosas (usaremos "Entregado")
        $managerOrdersCount = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status_id', $statusDeliveredId)
            ->count();

        $managerTotalUsd   = $managerOrdersCount * $this->managerCommission;
        $managerTotalLocal = $managerTotalUsd * $rate;

        // Todos los gerentes (para que admin los vea)
        $managersList = User::where('role_id', $managerRoleId)
            ->select('id', 'names', 'surnames', 'email')
            ->get();

        // Si sólo tienes 1 gerente, esto será básicamente un bucket único.
        $managers = $managersList->map(function (User $u) use ($managerOrdersCount, $managerTotalUsd, $managerTotalLocal) {
            // Por ahora todos ven el mismo total; si luego agregas manager_id en orders,
            // aquí se separa por usuario.
            return [
                'user_id'        => $u->id,
                'names'          => $u->names,
                'surnames'       => $u->surnames,
                'email'          => $u->email,
                'orders_count'   => $managerOrdersCount,
                'amount_usd'     => $managerTotalUsd,
                'amount_local'   => $managerTotalLocal,
            ];
        });

        return [
            'rate'      => $rate,
            'from'      => $from->toDateTimeString(),
            'to'        => $to->toDateTimeString(),
            'vendors'   => $vendors->values(),
            'deliverers' => $deliverers->values(),
            'managers'  => $managers->values(),
            'totals'    => [
                'vendors_usd'    => $vendors->sum('amount_usd'),
                'deliverers_usd' => $deliverers->sum('amount_usd'),
                'managers_usd'   => $managerTotalUsd,
                'all_usd'        => $vendors->sum('amount_usd') + $deliverers->sum('amount_usd') + $managerTotalUsd,
                'all_local'      => ($vendors->sum('amount_usd') + $deliverers->sum('amount_usd') + $managerTotalUsd) * $rate,
            ]
        ];
    }

    /**
     * Devuelve las ganancias "personales" de un usuario (por rol).
     */
    public function forUser(User $user, Carbon $from, Carbon $to): array
    {
        $roleDesc = $user->role?->description;
        $rate = (float) (Setting::get('rate_bcv_usd', 1) ?? 1);

        $statusDeliveredId = Status::where('description', 'Entregado')->value('id');

        $ordersCount = 0;
        $amountUsd   = 0;

        if ($roleDesc === 'Vendedor') {
            $ordersCount = Order::query()
                ->whereBetween('created_at', [$from, $to])
                ->where('status_id', $statusDeliveredId)
                ->where('agent_id', $user->id)
                ->count();

            $amountUsd = $ordersCount * $this->vendorCommission;
        } elseif ($roleDesc === 'Repartidor') {
            $ordersCount = Order::query()
                ->whereBetween('created_at', [$from, $to])
                ->where('status_id', $statusDeliveredId)
                ->where('deliverer_id', $user->id)
                ->count();

            $amountUsd = $ordersCount * $this->delivererCommission;
        } elseif ($roleDesc === 'Gerente') {
            // Por ahora, todos los gerentes comparten la bolsa global
            $ordersCount = Order::query()
                ->whereBetween('created_at', [$from, $to])
                ->where('status_id', $statusDeliveredId)
                ->count();

            $amountUsd = $ordersCount * $this->managerCommission;
        } else {
            // Otros roles (Admin, etc.) no tienen comisión
            $ordersCount = 0;
            $amountUsd   = 0;
        }

        return [
            'user_id'      => $user->id,
            'role'         => $roleDesc,
            'from'         => $from->toDateTimeString(),
            'to'           => $to->toDateTimeString(),
            'orders_count' => $ordersCount,
            'amount_usd'   => $amountUsd,
            'amount_local' => $amountUsd * $rate,
            'rate'         => $rate,
        ];
    }

    /**
     * Helper para mapear filas agregadas (por agente/repartidor) a estructura con datos del usuario.
     */
    protected function mapUserEarnings(
        Collection $rows,
        string $keyColumn,
        ?int $roleId,
        float $perOrderUsd,
        float $rate
    ): Collection {
        $userIds = $rows->pluck($keyColumn)->filter()->unique();

        $users = User::whereIn('id', $userIds)
            ->when($roleId, fn($q) => $q->where('role_id', $roleId))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($users, $keyColumn, $perOrderUsd, $rate) {
            $user = $users->get($row->{$keyColumn});
            if (!$user) return null;

            $ordersCount = (int) $row->orders_count;
            $amountUsd   = $ordersCount * $perOrderUsd;

            return [
                'user_id'      => $user->id,
                'names'        => $user->names,
                'surnames'     => $user->surnames,
                'email'        => $user->email,
                'orders_count' => $ordersCount,
                'amount_usd'   => $amountUsd,
                'amount_local' => $amountUsd * $rate,
            ];
        })->filter();
    }
}
