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

        $statusEntregado = Status::where('description', '=', 'Entregado')->first()?->id ?? 0;
        $statusCancelado = Status::where('description', '=', 'Cancelado')->first()?->id ?? 0;
        $statusRechazado = Status::where('description', '=', 'Rechazado')->first()?->id ?? 0;

        // 1. Efectividad de Productos
        $productMetrics = Product::withCount([
            'orderProducts as total_orders' => function ($query) use ($startDate, $endDate, $shopId) {
                $query->whereHas('order', function ($q) use ($startDate, $endDate, $shopId) {
                    $q->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                    if ($shopId) $q->where('shop_id', $shopId);
                });
            },
            'orderProducts as success_orders' => function ($query) use ($startDate, $endDate, $shopId, $statusEntregado) {
                $query->whereHas('order', function ($q) use ($startDate, $endDate, $shopId, $statusEntregado) {
                    $q->where('status_id', $statusEntregado)
                      ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                    if ($shopId) $q->where('shop_id', $shopId);
                });
            }
        ])->get(['*'])->map(function($product) use ($startDate, $endDate, $statusEntregado) {
            $product->effectiveness = $product->total_orders > 0 
                ? round((float)($product->success_orders / $product->total_orders) * 100, 2) 
                : 0;
            
            // Net Profit per Product
            // Revenue = sum(price * quantity) of delivered orders
            $revenue = DB::table('order_products')
                ->join('orders', 'order_products.order_id', '=', 'orders.id')
                ->where('order_products.product_id', $product->id)
                ->where('orders.status_id', $statusEntregado)
                ->whereBetween('orders.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->sum(DB::raw('order_products.price * order_products.quantity'));
            
            $cost = DB::table('order_products')
                ->join('orders', 'order_products.order_id', '=', 'orders.id')
                ->where('order_products.product_id', '=', $product->id)
                ->where('orders.status_id', '=', (int)$statusEntregado)
                ->whereBetween('orders.created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
                ->sum(DB::raw($product->cost_usd ?? 0 . ' * order_products.quantity'));

            $adSpend = ProductAdSpend::where('product_id', '=', $product->id)
                ->whereBetween('date', [$startDate, $endDate])
                ->sum('amount');

            $product->net_profit = $revenue - $cost - $adSpend;
            return $product;
        });

        // 2. Efectividad de Vendedoras
        $sellerMetrics = User::whereHas('role', fn($q) => $q->where('description', 'Vendedor'))
            ->withCount([
                'orders as total_assigned' => function ($query) use ($startDate, $endDate, $shopId) {
                    $query->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                    if ($shopId) $query->where('shop_id', $shopId);
                },
                'orders as success_delivered' => function ($query) use ($startDate, $endDate, $shopId, $statusEntregado) {
                    $query->where('status_id', '=', (int)$statusEntregado)
                          ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                    if ($shopId) $query->where('shop_id', '=', $shopId);
                }
            ])->get(['*'])->map(function($user) {
                $user->effectiveness = $user->total_assigned > 0 
                    ? round(($user->success_delivered / $user->total_assigned) * 100, 2) 
                    : 0;
                return $user;
            });

        // 3. Otros indicadores
        $ordersQuery = Order::whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        if ($shopId) $ordersQuery->where('shop_id', '=', $shopId);

        $deliveredOrders = (clone $ordersQuery)->where('status_id', '=', (int)$statusEntregado)->get(['*']);
        $avgOrderValue = $deliveredOrders->avg('current_total_price') ?? 0;

        $upsellsCount = OrderProduct::where('is_upsell', true)
            ->whereHas('order', function($q) use ($startDate, $endDate, $shopId) {
                $q->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                if ($shopId) $q->where('shop_id', $shopId);
            })->count();

        $cancellationsCount = (clone $ordersQuery)->where('status_id', (int)$statusCancelado)->count();
        
        $rejectedAfterShippingCount = (clone $ordersQuery)
            ->where('status_id', (int)$statusRechazado)
            ->where('was_shipped', true)
            ->count();

        // 4. Ganancia neta por día
        $dailyMetrics = [];
        $current = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        while ($current <= $end) {
            $date = $current->toDateString();
            
            $dayRevenue = DB::table('order_products')
                ->join('orders', 'order_products.order_id', '=', 'orders.id')
                ->where('orders.status_id', (int)$statusEntregado)
                ->whereDate('orders.created_at', $date)
                ->when($shopId, fn($q) => $q->where('orders.shop_id', $shopId))
                ->sum(DB::raw('order_products.price * order_products.quantity'));

            $dayCost = DB::table('order_products')
                ->join('orders', 'order_products.order_id', '=', 'orders.id')
                ->join('products', 'order_products.product_id', '=', 'products.id')
                ->where('orders.status_id', (int)$statusEntregado)
                ->whereDate('orders.created_at', $date)
                ->when($shopId, fn($q) => $q->where('orders.shop_id', $shopId))
                ->sum(DB::raw('products.cost_usd * order_products.quantity'));

            $dayAdSpend = ProductAdSpend::whereDate('date', $date)->sum('amount');

            $dailyMetrics[] = [
                'date' => $date,
                'revenue' => (float)$dayRevenue,
                'cost' => (float)$dayCost,
                'ad_spend' => (float)$dayAdSpend,
                'net_profit' => (float)($dayRevenue - $dayCost - $dayAdSpend),
            ];

            $current->addDay();
        }

        return response()->json([
            'products' => $productMetrics,
            'sellers' => $sellerMetrics,
            'summary' => [
                'avg_order_value' => round($avgOrderValue, 2),
                'upsells_count' => $upsellsCount,
                'cancellations_count' => $cancellationsCount,
                'rejected_after_shipping_count' => $rejectedAfterShippingCount,
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
