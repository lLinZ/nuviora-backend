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
        // Seleccionamos las tasas actuales
        $rateBCV = (float) (Setting::get('rate_bcv_usd', 1) ?? 1);
        $rateBinance = (float) (Setting::get('rate_binance_usd', 1) ?? 1);
        $rateEUR = (float) (Setting::get('rate_bcv_eur', 1) ?? 1);
        
        $rate = $rateBCV; // default logic keeps using BCV for internal calculations if needed

        // IDs de status importantes
        $statusCompletedId = Status::where('description', '=', 'Entregado')->value('id'); // Las vendedoras ganan cuando se entrega
        $statusDeliveredId = Status::where('description', '=', 'Entregado')->value('id');

        // ====== COMISIONES DESDE TABLA EARNINGS ======
        // Filtramos por created_at para capturar el momento de la transacción de ganancia
        $allEarnings = \App\Models\Earning::with(['user', 'order'])
            ->whereBetween('earning_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        // 1. VENDEDORAS
        $vendors = $this->groupEarningsByRole($allEarnings, 'vendedor', $rate);

        // 2. REPARTIDORES
        $deliverers = $this->groupEarningsByRole($allEarnings, 'repartidor', $rate);

        // 3. GERENTES
        // Los gerentes ven un consolidado global de órdenes (según tu lógica actual)
        // Pero aquí lo listamos por usuario que recibió la comisión.
        $managers = $this->groupEarningsByRole($allEarnings, 'gerente', $rate);

        // 4. AGENCIAS (Sólo si pasaron por En Ruta, es decir was_shipped = true)
        $agencyEarnings = $allEarnings->filter(function($e) {
            return $e->role_type === 'agencia' && $e->order?->was_shipped;
        });
        // Usamos groupEarningsByRole pero pasándole la colección ya filtrada y simulando que pedimos 'agencia' para que la lógica interna funcione
        // O mejor, pasamos las filtradas a la función, pero la función filtra por role_type otra vez.
        // Así que modificamos la lógica: Pasamos $allEarnings, y groupEarningsByRole filtra por role_type.
        // Para agencias, necesitamos filtrar ADEMÁS por was_shipped.
        // Solución: Filtramos primero una colección solo para agencias y se la pasamos a una función genérica o modificamos groupEarningsByRole.
        // Opto por: Pasar 'agencia' a groupEarningsByRole y dentro de esa función aplicar el filtro extra si es agencia.
        $agencies = $this->groupEarningsByRole($allEarnings, 'agencia', $rate);

        // 5. UPSELLS
        $upsells = $this->groupEarningsByRole($allEarnings, 'upsell', $rate);

        // 6. TOTAL POR USUARIO (Independiente del rol)
        $globalUsers = $this->groupEarningsByUser($allEarnings, $rate);

        return [
            'rates' => [
                'bcv' => $rateBCV,
                'binance' => $rateBinance,
                'bcv_eur' => $rateEUR,
            ],
            'from'      => $from->toDateTimeString(),
            'to'        => $to->toDateTimeString(),
            'vendors'   => $vendors,
            'deliverers' => $deliverers,
            'managers'  => $managers,
            'agencies'   => $agencies,
            'upsells'    => $upsells,
            'global_users' => $globalUsers,
            'totals'    => [
                'vendors_usd'    => $vendors->sum('amount_usd'),
                'deliverers_usd' => $deliverers->sum('amount_usd'),
                'managers_usd'   => $managers->sum('amount_usd'),
                'agencies_usd'   => $agencies->sum('amount_usd'),
                'upsells_usd'    => $upsells->sum('amount_usd'),
                'all_usd'        => $allEarnings->sum('amount_usd'),
            ],
            'orders_with_change' => Order::with('agency')
                ->whereBetween('updated_at', [$from->startOfDay(), $to->endOfDay()])
                ->where('change_amount', '>', 0)
                ->get()
                ->map(function($o) {
                    $amtCompany = (float) $o->change_amount_company;
                    $amtAgency  = (float) $o->change_amount_agency;

                    // Fallback para órdenes viejas o mal guardadas
                    if ($o->change_covered_by === 'company' && $amtCompany <= 0) {
                        $amtCompany = (float) $o->change_amount;
                    } elseif ($o->change_covered_by === 'agency' && $amtAgency <= 0) {
                        $amtAgency = (float) $o->change_amount;
                    }

                    return [
                        'id'               => $o->id,
                        'name'             => $o->name,
                        'change_amount'    => (float) $o->change_amount,
                        'covered_by'       => $o->change_covered_by,
                        'amount_company'   => $amtCompany,
                        'amount_agency'    => $amtAgency,
                        'agency_name'      => $o->agency?->names ?? 'N/A',
                        'agency_id'        => $o->agency_id,
                    ];
                }),
            'agency_settlement' => $this->calculateAgencySettlement($from, $to)
        ];
    }

    /**
     * Calcula la liquidación de agencias: Efectivo cobrado - Vuelto entregado
     */
    private function calculateAgencySettlement(Carbon $from, Carbon $to): Collection
    {
        // Comparte de Agencias: Tomar todas las órdenes con agencia asignada en el periodo
        $orders = Order::with(['payments', 'agency'])
            ->whereNotNull('agency_id')
            ->whereBetween('updated_at', [$from->startOfDay(), $to->endOfDay()])
            ->get();

        $statusDeliveredId = \App\Models\Status::where('description', 'Entregado')->value('id');
        $statusTransitId = \App\Models\Status::where('description', 'En ruta')->value('id');

        return $orders->groupBy('agency_id')
            ->map(function (Collection $agencyOrders) use ($statusDeliveredId, $statusTransitId) {
                $agency = $agencyOrders->first()->agency;
                if (!$agency) return null;

                $details = $agencyOrders->map(function (Order $o) {
                    $cashUSD = $o->payments->where('method', 'DOLARES_EFECTIVO')->sum('amount');
                    $cashVES = $o->payments->where('method', 'BOLIVARES_EFECTIVO')->sum('amount');
                    
                    $changeUSD = ($o->change_method_agency === 'DOLARES_EFECTIVO') ? (float) $o->change_amount_agency : 0;
                    $changeVES = ($o->change_method_agency === 'BOLIVARES_EFECTIVO') ? (float) $o->change_amount_agency : 0;

                    // Si solo marcó "agency" pero no puso monto exacto, usamos el change_amount total (fallback logic similar a orders_with_change)
                    if ($o->change_covered_by === 'agency' && $o->change_amount_agency <= 0) {
                        if ($o->change_method_agency === 'DOLARES_EFECTIVO') $changeUSD = (float) $o->change_amount;
                        if ($o->change_method_agency === 'BOLIVARES_EFECTIVO') $changeVES = (float) $o->change_amount;
                    }

                    $amtCompany = (float) $o->change_amount_company;
                    $methodCompany = $o->change_method_company;

                    // Fallback si marcó empresa pero no especificó monto
                    if ($o->change_covered_by === 'company' && $amtCompany <= 0) {
                        $amtCompany = (float) $o->change_amount;
                        $methodCompany = $o->change_method_company ?: $o->change_method_agency; // Intento de rescatar método si falta
                    }

                    // Intentar detectar la tasa de cambio usada en la orden (cobro o vuelto)
                    $hasVesPayment = $o->payments->contains(fn($p) => str_contains(strtoupper($p->method), 'BOLIVARES'));
                    $hasVesChange = str_contains(strtoupper($o->change_method_company ?? ''), 'BOLIVARES') || str_contains(strtoupper($o->change_method_agency ?? ''), 'BOLIVARES');
                    
                    $rateUsed = 0;
                    if ($hasVesPayment || $hasVesChange) {
                        $vesPayment = $o->payments->filter(fn($p) => str_contains(strtoupper($p->method), 'BOLIVARES'))->first();
                        $rateUsed = $vesPayment?->rate 
                                    ?: $vesPayment?->usd_rate 
                                    ?: $o->change_rate 
                                    ?: $o->exchange_rate 
                                    ?: (float) \App\Models\Setting::get('rate_binance_usd', 0)
                                    ?: (float) \App\Models\Setting::get('rate_bcv_usd', 0);
                    }

                    return [
                        'order_id'       => $o->id,
                        'order_name'     => $o->name,
                        'total_price'    => (float) $o->current_total_price,
                        'collected_usd'  => $cashUSD,
                        'collected_ves'  => $cashVES,
                        'change_usd'     => $changeUSD,
                        'change_ves'     => $changeVES,
                        'net_usd'        => $cashUSD - $changeUSD,
                        'net_ves'        => $cashVES - $changeVES,
                        'rate_ves'       => $rateUsed > 0 ? (float) $rateUsed : null,
                        'change_company' => $amtCompany,
                        'method_company' => $methodCompany ?? 'N/A',
                        'updated_at'     => $o->updated_at->toDateTimeString(),
                        'delivery_cost'  => $o->was_shipped ? (float) $o->delivery_cost : 0,
                    ];
                })
                // Filtrar solo órdenes ENTREGADAS para el detalle y la liquidación
                ->filter(function($d) use ($agencyOrders, $statusDeliveredId) {
                    $order = $agencyOrders->firstWhere('id', $d['order_id']);
                    return $order && $order->status_id == $statusDeliveredId;
                });

                if ($details->isEmpty()) return null;

                return [
                    'agency_id'           => $agency->id,
                    'agency_name'         => $agency->names,
                    'agency_color'        => $agency->color,
                    'count_delivered'     => $agencyOrders->where('status_id', $statusDeliveredId)->count(),
                    'count_in_transit'    => $agencyOrders->where('status_id', $statusTransitId)->count(),
                    'count_shipped'       => $agencyOrders->where('was_shipped', true)->count(),
                    'total_shipping_cost' => $details->sum('delivery_cost'),
                    'total_collected_usd' => $details->sum('collected_usd'),
                    'total_collected_ves' => $details->sum('collected_ves'),
                    'total_change_usd'    => $details->sum('change_usd'),
                    'total_change_ves'    => $details->sum('change_ves'),
                    'balance_usd'         => $details->sum('net_usd'),
                    'balance_ves'         => $details->sum('net_ves'),
                    'order_details'       => $details->values()
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Agrupa ganancias por usuario para un rol específico
     */
    private function groupEarningsByRole(Collection $earnings, string $role, float $rate): Collection
    {
        return $earnings->filter(function ($e) use ($role) {
                if ($e->role_type !== $role) return false;
                // Para agencias, validamos status En Ruta
                if ($role === 'agencia' && !$e->order?->was_shipped) return false;
                return true;
            })
            ->groupBy('user_id')
            ->map(function (Collection $rows) use ($rate) {
                $user = $rows->first()->user;
                if (!$user) return null;
                
                return [
                    'user_id'      => $user->id,
                    'names'        => $user->names,
                    'surnames'     => $user->surnames,
                    'email'        => $user->email,
                    'color'        => $user->color,
                    'orders_count' => $rows->unique('order_id')->count(),
                    'amount_usd'   => (float) $rows->sum('amount_usd'),
                    'amount_local' => (float) $rows->sum('amount_usd') * $rate,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Agrupa ganancias por usuario consolidando TODOS sus roles
     */
    private function groupEarningsByUser(Collection $earnings, float $rate): Collection
    {
        return $earnings->groupBy('user_id')
            ->map(function (Collection $rows) use ($rate) {
                $user = $rows->first()->user;
                if (!$user) return null;
                
                return [
                    'user_id'      => $user->id,
                    'names'        => $user->names,
                    'surnames'     => $user->surnames,
                    'email'        => $user->email,
                    'color'        => $user->color,
                    'role_name'    => $user->role?->description ?? 'Sin Rol',
                    'orders_count' => $rows->unique('order_id')->count(),
                    'amount_usd'   => (float) $rows->sum('amount_usd'),
                    'amount_local' => (float) $rows->sum('amount_usd') * $rate,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Devuelve las ganancias "personales" de un usuario (por rol).
     */
    public function forUser(User $user, Carbon $from, Carbon $to): array
    {
        $roleDesc = $user->role?->description;
        $rate = (float) (Setting::get('rate_bcv_usd', 1) ?? 1);

        // Consultamos directamente la tabla de ganancias para este usuario
        $earningsRecords = \App\Models\Earning::where('user_id', '=', $user->id)
            ->whereBetween('earning_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $totalUsd = (float) $earningsRecords->sum('amount_usd');
        $ordersCount = (int) $earningsRecords->unique('order_id')->count();

        $breakdown = [
            'orders' => (float) $earningsRecords->where('role_type', 'vendedor')->sum('amount_usd'),
            'upsells' => (float) $earningsRecords->where('role_type', 'upsell')->sum('amount_usd'),
            'repartidor' => (float) $earningsRecords->where('role_type', 'repartidor')->sum('amount_usd'),
        ];

        // CASO ESPECIAL: Gerente (actualmente ven la bolsa global si así se definió)
        if ($roleDesc === 'Gerente') {
            $statusDeliveredId = Status::where('description', '=', 'Entregado')->value('id');
            $ordersCount = Order::query()
                ->whereBetween('processed_at', [$from->startOfDay(), $to->endOfDay()])
                ->where('status_id', '=', $statusDeliveredId)
                ->count();
            $amountUsd = $ordersCount * $this->managerCommission;
        } else {
            $amountUsd   = $totalUsd;
        }


        return [
            'user_id'      => $user->id,
            'role'         => $roleDesc,
            'from'         => $from->toDateTimeString(),
            'to'           => $to->toDateTimeString(),
            'orders_count' => $ordersCount,
            'amount_usd'   => $amountUsd,
            'breakdown'    => $breakdown,

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
            ->when($roleId, fn($q) => $q->where('role_id', '=', $roleId))
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
