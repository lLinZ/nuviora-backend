<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Status;
use App\Models\User;
use App\Services\SalesGroups\LeaderCommissions;
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
    public function summary(Carbon $from, Carbon $to, ?int $agencyId = null): array
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
            ->when($agencyId, fn($q) => $q->where('user_id', $agencyId))
            ->get();

        // 1. VENDEDORAS
        $vendors = $this->groupEarningsByRole($allEarnings, ['vendedor', 'upsell'], $rate);

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

        // 6. LÍDERES: comisión de liderazgo (tabla aparte, ver LeaderCommissions)
        $leaders = app(LeaderCommissions::class)->byLeader($from->toDateString(), $to->toDateString());
        if ($agencyId) {
            $leaders = collect();
        }

        // 7. TOTAL POR USUARIO (Independiente del rol), con el liderazgo sumado a lo que se le paga a cada Líder
        $globalUsers = $this->addLeadership($this->groupEarningsByUser($allEarnings, $rate), $leaders, $rate);

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
            'leaders'    => $leaders,
            'global_users' => $globalUsers,
            'totals'    => [
                'vendors_usd'    => $vendors->sum('amount_usd'),
                'deliverers_usd' => $deliverers->sum('amount_usd'),
                'managers_usd'   => $managers->sum('amount_usd'),
                'agencies_usd'   => $agencies->sum('amount_usd'),
                'upsells_usd'    => $upsells->sum('amount_usd'),
                'leaders_usd'    => round($leaders->sum('amount_usd'), 2),
                'all_usd'        => $allEarnings->sum('amount_usd') + $leaders->sum('amount_usd'),
            ],
            'orders_with_change' => Order::with('agency')
                ->whereBetween('updated_at', [$from->startOfDay(), $to->endOfDay()])
                ->where('change_amount', '>', 0)
                ->when($agencyId, fn($q) => $q->where('agency_id', $agencyId)) // cada agencia, solo sus vueltos
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
            'agency_settlement' => $this->calculateAgencySettlement($from, $to, $agencyId),
            'refunds' => $agencyId ? [] : $this->refunds($from, $to),
        ];
    }

    /** Reembolsos del período (devoluciones, tarea 3b), por la fecha en que se hicieron. */
    public function refunds(Carbon $from, Carbon $to): array
    {
        $rows = \App\Models\OrderRefund::with(['order:id,name,agent_id', 'order.agent:id,names', 'user:id,names'])
            ->whereBetween('refunded_at', [$from->toDateString(), $to->toDateString()])
            ->orderBy('refunded_at')->orderBy('id')->get();

        return [
            'total_usd' => round($rows->sum('amount_usd'), 2),
            'count' => $rows->count(),
            'items' => $rows->map(fn ($r) => $r->toPayload() + ['seller_name' => $r->order?->agent?->names])->values(),
        ];
    }

    /**
     * Liquidación de agencias.
     *  - Carreras (tarea 5): se paga cada intento, con la tarifa que tenía la agencia, en la semana en
     *    que se hizo. Sale de sus ganancias (earnings agencia, una por carrera, más las de antes de este
     *    registro, una por orden) con fecha en el período.
     *  - Efectivo: lo cobrado menos el vuelto dado, de las órdenes entregadas en el período.
     */
    public function calculateAgencySettlement(Carbon $from, Carbon $to, ?int $agencyId = null): Collection
    {
        $rateBinanceNow = (float) (Setting::get('rate_binance_usd', 1) ?? 1);
        $rateEuroNow = (float) (Setting::get('rate_bcv_eur', 1) ?? 1);

        // Standardize the dates without modifying the original objects
        $startDate = (clone $from)->startOfDay();
        $endDate = (clone $to)->endOfDay();
        
        // 🔒 STRICT: Solo órdenes entregadas para la liquidación y métricas
        $statusDeliveredId = \App\Models\Status::where('description', 'Entregado')->value('id');

        // Comparte de Agencias: Tomar todas las órdenes con agencia asignada en el periodo
        $query = Order::with(['payments', 'agency'])
            ->whereNotNull('agency_id')
            ->where('status_id', $statusDeliveredId)
            ->whereBetween('processed_at', [$startDate, $endDate]);

        if ($agencyId) {
            $query->where('agency_id', $agencyId);
        }

        $orders = $query->get();
        $statusTransitId = \App\Models\Status::where('description', 'En ruta')->value('id');

        // Carreras del período, por su fecha (cada una es una ganancia de la agencia)
        $tripEarnings = \App\Models\Earning::with('order:id,name,is_exchange')
            ->where('role_type', 'agencia')
            ->whereBetween('earning_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->when($agencyId, fn ($q) => $q->where('user_id', $agencyId))
            ->get();
        $tripRows = \App\Models\AgencyTrip::whereIn('earning_id', $tripEarnings->pluck('id'))->get()->keyBy('earning_id');
        $tripsByAgency = $tripEarnings->groupBy('user_id');

        $agencyIds = $orders->pluck('agency_id')->merge($tripEarnings->pluck('user_id'))->filter()->unique()->values();
        $agencies = User::whereIn('id', $agencyIds)->get()->keyBy('id');

        return $agencyIds
            ->map(function ($id) use ($orders, $agencies, $tripsByAgency, $tripRows, $statusDeliveredId, $statusTransitId, $rateBinanceNow, $rateEuroNow) {
                $agency = $agencies->get($id);
                if (!$agency) return null;
                $agencyOrders = $orders->where('agency_id', $id)->values();

                $trips = ($tripsByAgency->get($id) ?? collect())->map(function ($e) use ($tripRows) {
                    $trip = $tripRows->get($e->id);

                    return [
                        'trip_id'      => $trip?->id,
                        'order_id'     => $e->order_id,
                        'order_name'   => $e->order?->name ?? "#{$e->order_id}",
                        'trip_date'    => $e->earning_date,
                        'type'         => $trip?->type ?? ($e->order?->is_exchange ? 'cambio' : 'normal'),
                        // 'anterior': pagada antes de que existiera el registro de carreras (una por orden)
                        'result'       => $trip ? $trip->result : 'anterior',
                        'amount_usd'   => (float) $e->amount_usd,
                    ];
                })->sortBy('trip_date')->values();
                $costByOrder = $trips->groupBy('order_id')->map(fn ($t) => $t->sum('amount_usd'));

                $details = $agencyOrders->map(function (Order $o) use ($rateBinanceNow, $rateEuroNow, $costByOrder) {
                    $cashUSD = (float) $o->payments->where('method', 'DOLARES_EFECTIVO')->sum('amount');
                    
                    // 1. INGRESOS (Payments in VES) -> Siempre Tasa Binance
                    $cashVES = (float) $o->payments
                        ->filter(fn($p) => $p->method === 'BOLIVARES_EFECTIVO')
                        ->map(fn($p) => (float)$p->amount * ($p->binance_usd_rate ?: $rateBinanceNow))
                        ->sum();
                    
                    // 2. VUELTOS (Change in VES) -> Siempre Tasa Euro
                    $changeUSD = ($o->change_method_agency === 'DOLARES_EFECTIVO') ? (float) $o->change_amount_agency : 0;
                    $changeVES_usd_equivalent = ($o->change_method_agency === 'BOLIVARES_EFECTIVO') ? (float) $o->change_amount_agency : 0;

                    // Fallback para montos no especificados
                    if ($o->change_covered_by === 'agency' && $o->change_amount_agency <= 0) {
                        if ($o->change_method_agency === 'DOLARES_EFECTIVO') $changeUSD = (float) $o->change_amount;
                        if ($o->change_method_agency === 'BOLIVARES_EFECTIVO') $changeVES_usd_equivalent = (float) $o->change_amount;
                    }

                    // Tasa Euro para el vuelto
                    $rateEuroForChange = $o->payments->firstWhere('eur_rate', '>', 0)?->eur_rate 
                                        ?: $o->change_rate // fallback to order field if exists
                                        ?: $rateEuroNow;

                    $changeVES = $changeVES_usd_equivalent * $rateEuroForChange;

                    $amtCompany = (float) $o->change_amount_company;
                    $methodCompany = $o->change_method_company;

                    if ($o->change_covered_by === 'company' && $amtCompany <= 0) {
                        $amtCompany = (float) $o->change_amount;
                        $methodCompany = $o->change_method_company ?: $o->change_method_agency; 
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
                        'rate_binance'   => $o->payments->firstWhere('binance_usd_rate', '>', 0)?->binance_usd_rate ?: $rateBinanceNow,
                        'rate_euro'      => $rateEuroForChange,
                        'change_company' => $amtCompany,
                        'method_company' => $methodCompany ?? 'N/A',
                        'updated_at'     => $o->updated_at->toDateTimeString(),
                        // Lo que se le paga por sus carreras de este período (se liquidan en tripDetails)
                        'delivery_cost'  => (float) ($costByOrder[$o->id] ?? 0),
                    ];
                })
                // Filtrar órdenes ENTREGADAS EXCLUSIVAMENTE para la liquidación
                ->filter(function($d) use ($agencyOrders, $statusDeliveredId) {
                    $order = $agencyOrders->firstWhere('id', $d['order_id']);
                    // 🔒 STRICT: Solo pagar/liquidar lo que está efectivamente ENTREGADO
                    return $order && ((int)$order->status_id === (int)$statusDeliveredId);
                });

                if ($details->isEmpty() && $trips->isEmpty()) return null;

                return [
                    'agency_id'           => $agency->id,
                    'agency_name'         => $agency->names,
                    'agency_color'        => $agency->color,
                    'delivery_rate'       => (float) $agency->delivery_cost,
                    'total_orders'        => $agencyOrders->where('status_id', $statusDeliveredId)->count(),
                    'count_delivered'     => $agencyOrders->where('status_id', $statusDeliveredId)->count(),
                    'count_in_transit'    => $agencyOrders->where('status_id', $statusTransitId)->count(),
                    'count_shipped'       => $agencyOrders->where('was_shipped', true)->count(),
                    'count_trips'         => $trips->count(),
                    'trips_by_type'       => $trips->countBy('type'),
                    'trips_by_result'     => $trips->countBy(fn ($t) => $t['result'] ?? 'en_curso'),
                    'total_shipping_cost' => round($trips->sum('amount_usd'), 2),
                    'trip_details'        => $trips,
                    'total_collected_usd' => $details->sum('collected_usd'),
                    'total_collected_ves' => $details->sum('collected_ves'),
                    'total_change_usd'    => $details->sum('change_usd'),
                    'total_change_ves'    => $details->sum('change_ves'),
                    'balance_usd'         => $details->sum('net_usd'),
                    'balance_ves'         => $details->sum('net_ves'),
                    'total_net_usd'       => $details->sum('net_usd'), // Alias for frontend compatibility
                    'total_net_ves'       => $details->sum('net_ves'), // Alias for frontend compatibility
                    'order_details'       => $details->values()
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Agrupa ganancias por usuario para un rol específico
     */
    private function groupEarningsByRole(Collection $earnings, string|array $role, float $rate): Collection
    {
        $roles = is_array($role) ? $role : [$role];

        return $earnings->filter(function ($e) use ($roles) {
                if (!in_array($e->role_type, $roles)) return false;
                // Para agencias, validamos status En Ruta
                if (in_array('agencia', $roles) && $e->role_type === 'agencia' && !$e->order?->was_shipped) return false;
                return true;
            })
            ->groupBy('user_id')
            ->map(function (Collection $rows) use ($rate, $roles) {
                $user = $rows->first()->user;
                if (!$user) return null;

                // Filas primarias = todo lo que NO sea upsell (vendedor, repartidor, gerente, agencia)
                $primaryRoles = array_values(array_filter($roles, fn($r) => $r !== 'upsell'));
                $primaryRows  = count($primaryRoles) > 0
                    ? $rows->whereIn('role_type', $primaryRoles)
                    : $rows; // si solo se pidió upsell, usar todas las filas como base
                $upsellRows   = $rows->where('role_type', 'upsell');

                $result = [
                    'user_id'       => $user->id,
                    'names'         => $user->names,
                    'surnames'      => $user->surnames,
                    'email'         => $user->email,
                    'color'         => $user->color,
                    'orders_count'  => $primaryRows->unique('order_id')->count(),
                    'upsells_count' => $upsellRows->count(),
                    'amount_usd'    => (float) $rows->sum('amount_usd'),
                    'amount_local'  => (float) $rows->sum('amount_usd') * $rate,
                ];

                // Incluimos el detalle orden por orden para todos
                $result['order_details'] = $rows->map(function ($e) use ($rate) {
                    return [
                        'order_id'     => $e->order_id,
                        'order_name'   => $e->order?->name ?? "#{$e->order_id}",
                        'earning_date' => $e->earning_date,
                        'role_type'    => $e->role_type,
                        'amount_usd'   => (float) $e->amount_usd,
                        'amount_local' => (float) $e->amount_usd * $rate,
                    ];
                })->values()->toArray();

                return $result;
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

    /** Suma la comisión de liderazgo al total a pagar de cada Líder (y la agrega si no tenía otras). */
    private function addLeadership(Collection $users, Collection $leaders, float $rate): Collection
    {
        foreach ($leaders as $leader) {
            $index = $users->search(fn ($u) => $u['user_id'] === $leader['user_id']);
            if ($index === false) {
                $users->push([
                    'user_id'      => $leader['user_id'],
                    'names'        => $leader['names'],
                    'surnames'     => $leader['surnames'],
                    'email'        => $leader['email'],
                    'color'        => $leader['color'],
                    'role_name'    => 'Líder',
                    'orders_count' => 0,
                    'amount_usd'   => 0.0,
                    'amount_local' => 0.0,
                ]);
                $index = $users->count() - 1;
            }
            $row = $users[$index];
            $row['role_name'] = 'Líder';
            $row['leadership_usd'] = $leader['amount_usd'];
            $row['amount_usd'] = round($row['amount_usd'] + $leader['amount_usd'], 2);
            $row['amount_local'] = $row['amount_usd'] * $rate;
            $users[$index] = $row;
        }

        return $users->sortByDesc('amount_usd')->values();
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

        // La Líder también cobra su comisión de liderazgo (spec §12.2)
        $leadership = (float) \App\Models\LeaderCommission::where('leader_id', $user->id)
            ->whereBetween('earning_date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount_usd');
        $totalUsd += $leadership;

        $breakdown = [
            'orders' => (float) $earningsRecords->where('role_type', 'vendedor')->sum('amount_usd'),
            'upsells' => (float) $earningsRecords->where('role_type', 'upsell')->sum('amount_usd'),
            'repartidor' => (float) $earningsRecords->where('role_type', 'repartidor')->sum('amount_usd'),
            'leadership' => round($leadership, 2),
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
