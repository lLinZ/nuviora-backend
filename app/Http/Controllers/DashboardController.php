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
            case 'Agencia':
                $data['stats'] = $this->getAgencyStats($user, $today, $rate);
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
        $statusCompletedId = Status::where('description', '=', 'Confirmado')->value('id');
        $statusDeliveredId = Status::where('description', '=', 'Entregado')->value('id');
        $statusCancelledId = Status::where('description', '=', 'Cancelado')->value('id');
        $statusNewId = Status::where('description', '=', 'Nuevo')->value('id');
        $statusAssignAgencyId = Status::where('description', '=', 'Asignar a agencia')->value('id');
        
        // Define statuses that are considered "Finalized" and shouldn't be in the Action Center
        // We'll exclude 'Entregado' (Delivered) and 'Confirmado' (Confirmed)
        // But we'll KEEP 'Cancelado' if they want to see why it wasn't assigned, or just filter it if it's truly done.
        // Given your feedback, let's make it show anything WITHOUT an agency that isn't delivered.
        $finalStatuses = Status::whereIn('description', ['Entregado', 'Confirmado'])->pluck('id')->filter()->toArray();

        // General Counts
        $created = Order::whereDate('created_at', '=', $date->toDateString())->count();
        $delivered = Order::whereDate('processed_at', '=', $date->toDateString())->where('status_id', '=', $statusDeliveredId)->count();
        $cancelled = Order::whereDate('created_at', '=', $date->toDateString())->where('status_id', '=', $statusCancelledId)->count();

        $totalSales = Order::whereDate('processed_at', '=', $date->toDateString())
            ->whereIn('status_id', [$statusCompletedId, $statusDeliveredId])
            ->sum('current_total_price') ?? 0;

        // Last 7 days sales history
        $salesHistory = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i);
            $salesHistory[] = [
                'date' => $d->format('d/m'),
                'count' => Order::whereDate('created_at', '=', $d->toDateString())->count()
            ];
        }

        // Pending Actions
        $pendingRejections = \App\Models\OrderRejectionReview::where('status', 'pending')->count();
        $pendingLocations = \App\Models\OrderLocationReview::where('status', 'pending')->count();

        // Top Performers (Last 7 days)
        $sevenDaysAgo = Carbon::today()->subDays(7);
        
        $topSellers = User::whereHas('role', fn($q) => $q->where('description', 'Vendedor'))
            ->withCount(['agentOrders' => function($q) use ($sevenDaysAgo) {
                $q->where('created_at', '>=', $sevenDaysAgo);
            }])
            ->orderBy('agent_orders_count', 'desc')
            ->limit(5)
            ->get(['id', 'names']);

        $topDeliverers = User::whereHas('role', fn($q) => $q->where('description', 'Repartidor'))
            ->withCount(['delivererOrders' => function($q) use ($sevenDaysAgo) {
                $q->where('processed_at', '>=', $sevenDaysAgo)
                  ->whereHas('status', fn($s) => $s->where('description', 'Entregado'));
            }])
            ->orderBy('deliverer_orders_count', 'desc')
            ->limit(5)
            ->get(['id', 'names']);

        return [
            'total_sales' => $totalSales,
            'orders_today' => [
                'created' => $created,
                'delivered' => $delivered,
                'cancelled' => $cancelled,
            ],
            'unassigned_agency_count' => (int) Order::whereNull('agency_id')->count(),
            'unassigned_orders' => Order::whereNull('agency_id')
                ->latest()
                ->limit(5)
                ->get(['id', 'name', 'current_total_price', 'created_at']),
            'pending_reviews' => [
                'rejections' => $pendingRejections,
                'locations' => $pendingLocations,
            ],
            'top_sellers' => $topSellers,
            'top_deliverers' => $topDeliverers,
            'sales_history' => $salesHistory
        ];
    }

    private function getVendorStats($user, $date, $rate)
    {
        $statusCompletedId = Status::where('description', '=', 'Confirmado')->value('id');
        $commissionPerOrder = 1.0; // $1 USD

        $assigned = Order::whereDate('created_at', '=', $date->toDateString())->where('agent_id', '=', $user->id)->count();
        $completed = Order::whereDate('processed_at', '=', $date->toDateString())
            ->where('status_id', '=', $statusCompletedId)
            ->where('agent_id', '=', $user->id)
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
        $statusDeliveredId = Status::where('description', '=', 'Entregado')->value('id');
        $commissionPerOrder = 2.5; // $2.5 USD

        $assigned = Order::whereDate('created_at', '=', $date)->where('deliverer_id', '=', $user->id)->count();
        $delivered = Order::whereDate('processed_at', '=', $date)
            ->where('status_id', '=', $statusDeliveredId)
            ->where('deliverer_id', '=', $user->id)
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

    private function getAgencyStats($user, $date, $rate)
    {
        $statusDeliveredId = Status::where('description', '=', 'Entregado')->value('id');

        $assigned = Order::where('agency_id', '=', $user->id)
            ->whereDate('created_at', $date)
            ->count();

        $delivered = Order::where('agency_id', '=', $user->id)
            ->where('status_id', '=', $statusDeliveredId)
            ->whereDate('processed_at', $date)
            ->count();

        return [
            'orders' => [
                'assigned' => $assigned,
                'delivered' => $delivered,
            ],
            'message' => 'Resumen de entregas para tu agencia'
        ];
    }
}
