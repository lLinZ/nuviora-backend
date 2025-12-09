<?php
// app/Observers/OrderObserver.php
namespace App\Observers;

use App\Models\Order;
use App\Services\Assignment\AssignOrderService;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    public function created(Order $order): void
    {
        try {
            app(AssignOrderService::class)->assignOne($order);
        } catch (\Throwable $e) {
            Log::error('Auto-assign failed: ' . $e->getMessage(), ['order_id' => $order->id]);
        }
    }
}
