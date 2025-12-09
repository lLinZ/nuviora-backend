<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Status;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $role = $user->role?->description;

        // Common data
        $today = Carbon::today();
        $rate = (float) (Setting::get('exchange_rate_usd', 1) ?? 1);

        $data = [
            'role' => $role,
            'today' => $today->toDateTimeString(),
            'rate' => $rate,
            'stats' => [],
        ];

        // Role specific logic
        switch ($role) {
            case 'Admin':
            case 'Gerente':
            case 'Master':
                $data['stats'] = $this->getAdminStats($today, $rate);
                break;
            case 'Vendedor':
                $data['stats'] = $this->getVendorStats($user, $today, $rate);
                break;
            case 'Repartidor':
                $data['stats'] = $this->getDelivererStats($user, $today, $rate);
                break;
            default:
                $data['stats'] = ['message' => 'No stats available for this role'];
        }

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    private function getAdminStats($date, $rate)
    {
        $statusCompletedId = Status::where('description', 'Confirmado')->value('id');
        $statusDeliveredId = Status::where('description', 'Entregado')->value('id');
        $statusCancelledId = Status::where('description', 'Cancelado')->value('id');
        $statusNewId = Status::where('description', 'Nuevo')->value('id');

        // Total Earnings (Delivered orders today * avg ticket or just sum of order totals if available)
        // For now, let's assume earnings based on commissions logic for simplicity as "Company Earnings" might be complex
        // Or better, let's show Total Sales Volume (sum of order totals)
        // Assuming 'total' column exists in orders, if not we might need to sum products.
        // Let's use count for now as per previous dashboard design, plus commission totals.
        
        // Orders Breakdown
        $created = Order::whereDate('created_at', $date)->count();
        $completed = Order::whereDate('processed_at', $date)->where('status_id', $statusCompletedId)->count();
        $delivered = Order::whereDate('processed_at', $date)->where('status_id', $statusDeliveredId)->count();
        $cancelled = Order::whereDate('created_at', $date)->where('status_id', $statusCancelledId)->count(); // Cancelled today

        // Earnings (Estimation based on 2.5 commission logic or just sales? Let's use simple logic for now)
        // Let's calculate estimated earnings for the company based on delivered orders * average cart (mocked or real if available)
        // Actually, let's just show Counts for now as "Earnings" usually requires strict financial logic.
        // User asked for "Ganancias totales del dia". Let's try to sum `total` if it exists.
        // Order model usually has total.
        
        $totalSales = Order::whereDate('created_at', $date) // Or processed_at
            ->whereIn('status_id', [$statusCompletedId, $statusDeliveredId])
            ->sum('total') ?? 0;

        return [
            'total_sales' => $totalSales,
            'orders' => [
                'created' => $created,
                'completed' => $completed,
                'delivered' => $delivered,
                'cancelled' => $cancelled,
            ],
            // 'top_sellers' => ... (can implement later if needed, keep simple first)
        ];
    }

    private function getVendorStats($user, $date, $rate)
    {
        $statusCompletedId = Status::where('description', 'Confirmado')->value('id');
        $commissionPerOrder = 1.0; // $1 USD

        $assigned = Order::whereDate('created_at', $date)->where('agent_id', $user->id)->count();
        $completed = Order::whereDate('processed_at', $date)
            ->where('status_id', $statusCompletedId)
            ->where('agent_id', $user->id)
            ->count();
        
        $earningsUsd = $completed * $commissionPerOrder;

        return [
            'earnings_usd' => $earningsUsd,
            'earnings_local' => $earningsUsd * $rate,
            'orders' => [
                'assigned' => $assigned,
                'completed' => $completed,
            ],
            'rule' => '1 USD por orden completada'
        ];
    }

    private function getDelivererStats($user, $date, $rate)
    {
        $statusDeliveredId = Status::where('description', 'Entregado')->value('id');
        $commissionPerOrder = 2.5; // $2.5 USD

        $assigned = Order::whereDate('created_at', $date)->where('deliverer_id', $user->id)->count();
        $delivered = Order::whereDate('processed_at', $date)
            ->where('status_id', $statusDeliveredId)
            ->where('deliverer_id', $user->id)
            ->count();
            
        $earningsUsd = $delivered * $commissionPerOrder;

        // Stock count (assigned items not yet delivered/returned)
        // For simplicity, just count products in "Deliverer Stock" using DelivererStockController logic if needed
        // Or just return 0 for now until we link that up.
        
        return [
            'earnings_usd' => $earningsUsd,
            'earnings_local' => $earningsUsd * $rate,
            'orders' => [
                'assigned' => $assigned,
                'delivered' => $delivered,
            ],
            'rule' => '2.5 USD por orden entregada'
        ];
    }
}
