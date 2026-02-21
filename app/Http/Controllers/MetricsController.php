<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Models\Status;
use App\Models\ProductAdSpend;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MetricsController extends Controller
{
    public function index(Request $request)
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', Carbon::now()->toDateString());
        $shopId = $request->get('shop_id');
        $cityId = $request->get('city_id');
        $agencyId = $request->get('agency_id');

        $statusEntregado = Status::whereRaw('LOWER(description) = ?', ['entregado'])->first()?->id ?? 0;
        $statusCancelado = Status::whereRaw('LOWER(description) = ?', ['cancelado'])->first()?->id ?? 0;
        $statusRechazado = Status::whereRaw('LOWER(description) = ?', ['rechazado'])->first()?->id ?? 0;
        $statusConfirmado = Status::whereRaw('LOWER(description) = ?', ['confirmado'])->first()?->id ?? 0;
        $statusAsignadoAgencia = Status::whereRaw('LOWER(description) = ?', ['asignar a agencia'])->first()?->id ?? 0;
        $statusAsignadoRepartidor = Status::whereRaw('LOWER(description) = ?', ['asignado a repartidor'])->first()?->id ?? 0;
        $statusEnRuta = Status::whereRaw('LOWER(description) = ?', ['en ruta'])->first()?->id ?? 0;

        // 1. Efectividad de Productos
        // ✅ Contamos ÓRDENES ÚNICAS por status final — no cuántas veces pasó por un status
        $productMetrics = Product::withCount([
            'orderProducts as total_orders' => function ($query) use ($startDate, $endDate, $shopId, $cityId, $agencyId) {
                $query->whereHas('order', function ($q) use ($startDate, $endDate, $shopId, $cityId, $agencyId) {
                    $q->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                    if ($shopId) $q->where('shop_id', '=', $shopId);
                    if ($cityId) $q->where('city_id', '=', $cityId);
                    if ($agencyId) $q->where('agency_id', '=', $agencyId);
                })->select(DB::raw('COUNT(DISTINCT order_id)'));
            },
            'orderProducts as success_orders' => function ($query) use ($startDate, $endDate, $shopId, $cityId, $agencyId, $statusEntregado) {
                $query->whereHas('order', function ($q) use ($startDate, $endDate, $shopId, $cityId, $agencyId, $statusEntregado) {
                    // ✅ status_id actual = Entregado → estado FINAL de la orden
                    $q->where('status_id', '=', (int)$statusEntregado)
                      ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                    if ($shopId) $q->where('shop_id', '=', $shopId);
                    if ($cityId) $q->where('city_id', '=', $cityId);
                    if ($agencyId) $q->where('agency_id', '=', $agencyId);
                })->select(DB::raw('COUNT(DISTINCT order_id)'));
            }
        ])->get(['*'])->map(function($product) use ($startDate, $endDate, $statusEntregado, $shopId, $cityId, $agencyId) {
            $product->effectiveness = $product->total_orders > 0 
                ? round((float)($product->success_orders / $product->total_orders) * 100, 2) 
                : 0;
            
            // Net Profit per Product - SOLO productos base (NO upsells)
            $revenue = DB::table('order_products')
                ->join('orders', 'order_products.order_id', '=', 'orders.id')
                ->where('order_products.product_id', '=', $product->id)
                ->where('order_products.is_upsell', '=', false) // ✅ SOLO productos base
                ->where('orders.status_id', '=', (int)$statusEntregado)
                ->whereBetween('orders.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->when($shopId, fn($q) => $q->where('orders.shop_id', '=', $shopId))
                ->when($cityId, fn($q) => $q->where('orders.city_id', '=', $cityId))
                ->when($agencyId, fn($q) => $q->where('orders.agency_id', '=', $agencyId))
                ->sum(DB::raw('order_products.price * order_products.quantity'));
            
            $cost = DB::table('order_products')
                ->join('orders', 'order_products.order_id', '=', 'orders.id')
                ->where('order_products.product_id', '=', $product->id)
                ->where('order_products.is_upsell', '=', false) // ✅ SOLO productos base
                ->where('orders.status_id', '=', (int)$statusEntregado)
                ->whereBetween('orders.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->when($shopId, fn($q) => $q->where('orders.shop_id', '=', $shopId))
                ->when($cityId, fn($q) => $q->where('orders.city_id', '=', $cityId))
                ->when($agencyId, fn($q) => $q->where('orders.agency_id', '=', $agencyId))
                ->sum(DB::raw(($product->cost_usd ?? 0) . ' * order_products.quantity'));

            $adSpend = ProductAdSpend::where('product_id', '=', $product->id)
                ->whereBetween('date', [$startDate, $endDate])
                ->sum('amount');

            // ✅ Calcular TODAS las comisiones de órdenes que contienen este producto (base, no upsells)
            $commissions = DB::table('earnings')
                ->join('orders', 'earnings.order_id', '=', 'orders.id')
                ->join('order_products', 'orders.id', '=', 'order_products.order_id')
                ->where('order_products.product_id', '=', $product->id)
                ->where('order_products.is_upsell', '=', false)
                ->where('orders.status_id', '=', (int)$statusEntregado)
                ->whereBetween('orders.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->when($shopId, fn($q) => $q->where('orders.shop_id', '=', $shopId))
                ->when($cityId, fn($q) => $q->where('orders.city_id', '=', $cityId))
                ->when($agencyId, fn($q) => $q->where('orders.agency_id', '=', $agencyId))
                ->sum('earnings.amount_usd');

            $product->revenue = $revenue;
            $product->net_profit = $revenue - $cost - $adSpend - $commissions; // ✅ Restar todo
            return $product;
        });

        // 2. Efectividad de Vendedoras — atribución al PRIMER asignado
        // ✅ Cada orden se cuenta UNA SOLA VEZ, para quien la recibió primero.
        //    Si se reasignó, la primera vendedora "la perdió" y eso baja su efectividad.
        //    La suma de totales de todas las vendedoras = total real de órdenes.
        $sellerIds = User::whereHas('role', fn($q) => $q->whereRaw('LOWER(description) = ?', ['vendedor']))->pluck('id');

        // SECTION 2A: Business Metrics (First Assignment / Conversion)
        // Based on orders CREATED in the date range, attributed to the FIRST seller assigned.
        $firstAssignmentSubquery = DB::table('order_tracking_comprehensive_logs as tl_inner')
            ->selectRaw('MIN(tl_inner.id) as first_log_id')
            ->join('orders as o_inner', 'o_inner.id', '=', 'tl_inner.order_id')
            ->whereBetween('o_inner.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->whereIn('tl_inner.seller_id', $sellerIds)
            ->when($shopId,   fn($q) => $q->where('o_inner.shop_id',   $shopId))
            ->when($cityId,   fn($q) => $q->where('o_inner.city_id',   $cityId))
            ->when($agencyId, fn($q) => $q->where('o_inner.agency_id', $agencyId))
            ->groupBy('tl_inner.order_id');

        $businessRaw = DB::table('order_tracking_comprehensive_logs as tl')
            ->joinSub($firstAssignmentSubquery, 'fa', 'fa.first_log_id', '=', 'tl.id')
            ->join('orders as o', 'o.id', '=', 'tl.order_id')
            ->selectRaw('
                tl.seller_id as agent_id,
                COUNT(DISTINCT tl.order_id) as total_unique,
                COUNT(DISTINCT CASE WHEN o.status_id = ? AND o.agent_id = tl.seller_id THEN tl.order_id END) as delivered_by_self,
                COUNT(DISTINCT CASE WHEN o.status_id = ? AND o.agent_id != tl.seller_id THEN tl.order_id END) as delivered_by_other
            ', [(int)$statusEntregado, (int)$statusEntregado])
            ->groupBy('tl.seller_id')
            ->get()
            ->keyBy('agent_id');

        // Business Status Breakdown
        $businessStatusBreakdown = DB::table('order_tracking_comprehensive_logs as tl')
            ->joinSub($firstAssignmentSubquery, 'fa', 'fa.first_log_id', '=', 'tl.id')
            ->join('orders as o',   'o.id',        '=', 'tl.order_id')
            ->join('statuses as s', 'o.status_id', '=', 's.id')
            ->selectRaw('tl.seller_id, s.description as status_name, COUNT(DISTINCT tl.order_id) as count')
            ->groupBy('tl.seller_id', 's.description')
            ->get()
            ->groupBy('seller_id');

        $sellerMetrics = User::whereHas('role', fn($q) => $q->whereRaw('LOWER(description) = ?', ['vendedor']))
            ->get(['id', 'names', 'surnames', 'color'])
            ->map(function($user) use ($businessRaw, $businessStatusBreakdown) {
                $row              = $businessRaw->get($user->id);
                $total            = (int)($row?->total_unique       ?? 0);
                $deliveredBySelf  = (int)($row?->delivered_by_self  ?? 0);
                $deliveredByOther = (int)($row?->delivered_by_other ?? 0);

                $user->total_assigned      = $total;
                $user->success_delivered   = $deliveredBySelf;
                $user->delivered_by_other  = $deliveredByOther;
                $user->delivery_rate       = $total > 0 ? round(($deliveredBySelf / $total) * 100, 2) : 0;
                $user->status_breakdown    = $businessStatusBreakdown->get($user->id, collect())
                    ->map(fn($s) => ['status' => $s->status_name, 'count' => (int)$s->count])
                    ->sortByDesc('count')->values();
                return $user;
            });

        // SECTION 2B: Workload/Activity Metrics (Based on ACTIVITY and Assignments)
        // This reflects what the seller handled/managed in this period.
        // Total Unique = Orders where the seller appeared in the log during this period (WORKLOAD)
        $workloadRaw = DB::table('order_tracking_comprehensive_logs as tl')
            ->join('orders as o', 'o.id', '=', 'tl.order_id')
            ->whereBetween('tl.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->whereIn('tl.seller_id', $sellerIds)
            ->when($shopId,   fn($q) => $q->where('o.shop_id',   $shopId))
            ->when($cityId,   fn($q) => $q->where('o.city_id',   $cityId))
            ->when($agencyId, fn($q) => $q->where('o.agency_id', $agencyId))
            ->selectRaw('tl.seller_id as agent_id, COUNT(DISTINCT tl.order_id) as total_unique')
            ->groupBy('tl.seller_id')
            ->get()
            ->keyBy('agent_id');

        // Delivered count: Orders that generated a commission for the seller in this period (Matches Financial Reports)
        $deliveredWorkload = DB::table('earnings as e')
            ->join('orders as o', 'o.id', '=', 'e.order_id')
            ->where('e.role_type', 'vendedor')
            ->whereBetween('e.earning_date', [$startDate, $endDate])
            ->when($shopId,   fn($q) => $q->where('o.shop_id',   $shopId))
            ->when($cityId,   fn($q) => $q->where('o.city_id',   $cityId))
            ->when($agencyId, fn($q) => $q->where('o.agency_id', $agencyId))
            ->selectRaw('e.user_id as agent_id, COUNT(DISTINCT e.order_id) as count')
            ->groupBy('e.user_id')
            ->get()
            ->keyBy('agent_id');

        // Rescued logic: Orders delivered (with commission) in this period where the current agent is NOT the first one assigned.
        $firstSellerUniversal = DB::table('order_tracking_comprehensive_logs as tl_inner')
            ->selectRaw('MIN(tl_inner.id) as first_log_id, tl_inner.order_id')
            ->groupBy('tl_inner.order_id');

        $rescuedWorkload = DB::table('orders as o')
            ->join('earnings as e', 'e.order_id', '=', 'o.id')
            ->joinSub($firstSellerUniversal, 'fs', 'fs.order_id', '=', 'o.id')
            ->join('order_tracking_comprehensive_logs as tl_first', 'tl_first.id', '=', 'fs.first_log_id')
            ->where('e.role_type', 'vendedor')
            ->whereBetween('e.earning_date', [$startDate, $endDate])
            ->whereColumn('tl_first.seller_id', '!=', 'o.agent_id')
            ->selectRaw('o.agent_id as seller_id, COUNT(DISTINCT o.id) as rescued_count')
            ->groupBy('o.agent_id')
            ->get()
            ->keyBy('seller_id');

        // Status breakdown based on the orders they TOUCHED in the period
        $workloadStatusBreakdown = DB::table('order_tracking_comprehensive_logs as tl')
            ->join('orders as o',   'o.id',        '=', 'tl.order_id')
            ->join('statuses as s', 'o.status_id', '=', 's.id')
            ->whereIn('tl.seller_id', $sellerIds)
            ->whereBetween('tl.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->selectRaw('tl.seller_id, s.description as status_name, COUNT(DISTINCT tl.order_id) as count')
            ->groupBy('tl.seller_id', 's.description')
            ->get()
            ->groupBy('seller_id');

        $workloadMetrics = User::whereHas('role', fn($q) => $q->whereRaw('LOWER(description) = ?', ['vendedor']))
            ->get(['id', 'names', 'surnames', 'color'])
            ->map(function($user) use ($workloadRaw, $workloadStatusBreakdown, $rescuedWorkload, $deliveredWorkload) {
                $row              = $workloadRaw->get($user->id);
                $deliveredRow     = $deliveredWorkload->get($user->id);
                
                $total            = (int)($row?->total_unique   ?? 0);
                $deliveredTotal   = (int)($deliveredRow?->count ?? 0);
                $rescued          = (int)($rescuedWorkload->get($user->id)?->rescued_count ?? 0);

                $user->total_assigned      = $total;
                $user->success_delivered   = $deliveredTotal; 
                $user->rescued_from_other  = $rescued;
                $user->delivery_rate       = $total > 0 ? round(($deliveredTotal / $total) * 100, 2) : 0;
                $user->status_breakdown    = $workloadStatusBreakdown->get($user->id, collect())
                    ->map(fn($s) => ['status' => $s->status_name, 'count' => (int)$s->count])
                    ->sortByDesc('count')->values();
                return $user;
            });

        // 2b. Efectividad de Agencias
        $agencyIds = User::whereHas('role', fn($q) => $q->whereRaw('LOWER(description) = ?', ['agencia']))->pluck('id');

        $agencyRaw = DB::table('orders')
            ->selectRaw('
                agency_id,
                COUNT(DISTINCT id) as total_unique,
                COUNT(DISTINCT CASE WHEN status_id = ? THEN id END) as delivered_unique
            ', [(int)$statusEntregado])
            ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->whereIn('agency_id', $agencyIds)
            ->when($shopId, fn($q) => $q->where('shop_id', $shopId))
            ->when($cityId, fn($q) => $q->where('city_id', $cityId))
            ->groupBy('agency_id')
            ->get()
            ->keyBy('agency_id');

        // ✅ Desglose por status final para agencias
        $agencyStatusBreakdown = DB::table('orders')
            ->join('statuses', 'orders.status_id', '=', 'statuses.id')
            ->selectRaw('orders.agency_id, statuses.description as status_name, COUNT(DISTINCT orders.id) as count')
            ->whereBetween('orders.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->whereIn('orders.agency_id', $agencyIds)
            ->when($shopId, fn($q) => $q->where('orders.shop_id', $shopId))
            ->when($cityId, fn($q) => $q->where('orders.city_id', $cityId))
            ->groupBy('orders.agency_id', 'statuses.description')
            ->get()
            ->groupBy('agency_id');

        $agencyMetrics = User::whereHas('role', fn($q) => $q->whereRaw('LOWER(description) = ?', ['agencia']))
            ->get(['id', 'names', 'surnames'])
            ->map(function($user) use ($agencyRaw, $agencyStatusBreakdown) {
                $row = $agencyRaw->get($user->id);
                $total     = $row?->total_unique ?? 0;
                $delivered = $row?->delivered_unique ?? 0;
                $user->total_assigned    = $total;
                $user->success_delivered = $delivered;
                $user->delivery_rate     = $total > 0 ? round(($delivered / $total) * 100, 2) : 0;
                $user->status_breakdown  = $agencyStatusBreakdown->get($user->id, collect())
                    ->map(fn($s) => ['status' => $s->status_name, 'count' => (int)$s->count])
                    ->sortByDesc('count')
                    ->values();
                return $user;
            });

        // 3. Otros indicadores
        // ✅ Todos los filtros aplicados — created_at es el único criterio de fecha
        $ordersQuery = Order::whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        if ($shopId)   $ordersQuery->where('shop_id',   '=', $shopId);
        if ($cityId)   $ordersQuery->where('city_id',   '=', $cityId);
        if ($agencyId) $ordersQuery->where('agency_id', '=', $agencyId);

        $deliveredOrders = (clone $ordersQuery)->where('status_id', '=', (int)$statusEntregado)->get(['current_total_price']);
        $avgOrderValue = $deliveredOrders->avg('current_total_price') ?? 0;

        $upsellsCount = OrderProduct::where('is_upsell', '=', true)
            ->whereHas('order', function($q) use ($startDate, $endDate, $shopId, $cityId, $agencyId) {
                $q->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                if ($shopId)   $q->where('shop_id',   '=', $shopId);
                if ($cityId)   $q->where('city_id',   '=', $cityId);
                if ($agencyId) $q->where('agency_id', '=', $agencyId);
            })->count();

        $cancellationsCount = (clone $ordersQuery)->where('status_id', '=', (int)$statusCancelado)->count();

        $rejectedAfterShippingCount = (clone $ordersQuery)
            ->where('status_id', (int)$statusRechazado)
            ->where('was_shipped', true)
            ->count();

        // 4. Ganancia neta por día
        $dailyMetrics = [];
        $current = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // Safety check to prevent extreme loops (max 90 days)
        if ($current->diffInDays($end) > 90) {
            $current = (clone $end)->subDays(90);
        }
        
        // Ensure start is not after end
        if ($current > $end) {
            $current = clone $end;
        }

        while ($current <= $end) {
            $date = $current->toDateString();
            
            $dayRevenue = DB::table('order_products')
                ->join('orders', 'order_products.order_id', '=', 'orders.id')
                ->where('orders.status_id', '=', (int)$statusEntregado)
                ->whereDate('orders.created_at', '=', $date)
                ->when($shopId, fn($q) => $q->where('orders.shop_id', '=', $shopId))
                ->when($cityId, fn($q) => $q->where('orders.city_id', '=', $cityId))
                ->when($agencyId, fn($q) => $q->where('orders.agency_id', '=', $agencyId))
                ->sum(DB::raw('order_products.price * order_products.quantity'));

            $dayCost = DB::table('order_products')
                ->join('orders', 'order_products.order_id', '=', 'orders.id')
                ->join('products', 'order_products.product_id', '=', 'products.id')
                ->where('orders.status_id', '=', (int)$statusEntregado)
                ->whereDate('orders.created_at', '=', $date)
                ->when($shopId, fn($q) => $q->where('orders.shop_id', '=', $shopId))
                ->when($cityId, fn($q) => $q->where('orders.city_id', '=', $cityId))
                ->when($agencyId, fn($q) => $q->where('orders.agency_id', '=', $agencyId))
                ->sum(DB::raw('products.cost_usd * order_products.quantity'));

            $dayAdSpend = ProductAdSpend::whereDate('date', '=', $date)->sum('amount');

            // ✅ Sumar TODAS las comisiones del día (vendedores + agencias + repartidores)
            $dayCommissions = DB::table('earnings')
                ->join('orders', 'earnings.order_id', '=', 'orders.id')
                ->where('orders.status_id', '=', (int)$statusEntregado)
                ->whereDate('orders.created_at', '=', $date)
                ->when($shopId, fn($q) => $q->where('orders.shop_id', '=', $shopId))
                ->when($cityId, fn($q) => $q->where('orders.city_id', '=', $cityId))
                ->when($agencyId, fn($q) => $q->where('orders.agency_id', '=', $agencyId))
                ->sum('earnings.amount_usd');

            $dailyMetrics[] = [
                'date' => $date,
                'revenue' => (float)$dayRevenue,
                'cost' => (float)$dayCost,
                'ad_spend' => (float)$dayAdSpend,
                'commissions' => (float)$dayCommissions, // ✅ Agregar para visibilidad
                'net_profit' => (float)($dayRevenue - $dayCost - $dayAdSpend - $dayCommissions), // ✅ Restar comisiones
            ];

            $current->addDay();
        }

        // ✅ Efectividad global: órdenes únicas entregadas ÷ total único de órdenes
        $globalTotal     = (clone $ordersQuery)->count();
        $globalDelivered = (clone $ordersQuery)->where('status_id', '=', (int)$statusEntregado)->count();
        $globalEffectiveness = $globalTotal > 0 ? round(($globalDelivered / $globalTotal) * 100, 2) : 0;

        return response()->json([
            'products' => $productMetrics,
            'sellers'  => $sellerMetrics,
            'workload' => $workloadMetrics,
            'agencies' => $agencyMetrics,
            'summary'  => [
                'avg_order_value'               => round($avgOrderValue, 2),
                'upsells_count'                 => $upsellsCount,
                'cancellations_count'           => $cancellationsCount,
                'rejected_after_shipping_count' => $rejectedAfterShippingCount,
                'global_total_orders'           => $globalTotal,
                'global_delivered_orders'       => $globalDelivered,
                'global_effectiveness'          => $globalEffectiveness, // % órdenes únicas entregadas
            ],
            'daily' => $dailyMetrics,
        ]);
    }

    public function storeAdSpend(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0',
        ]);

        $adSpend = ProductAdSpend::updateOrCreate(
            ['product_id' => $data['product_id'], 'date' => $data['date']],
            ['amount' => $data['amount']]
        );

        return response()->json($adSpend);
    }
}
