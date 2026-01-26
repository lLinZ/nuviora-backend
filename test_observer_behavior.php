<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Order;
use App\Models\Status;

// Pick an order
$order = Order::first();
if (!$order) { exit("No order found"); }

$currentStatus = $order->status_id;
$newStatus = Status::where('id', '!=', $currentStatus)->first()->id;

echo "Changing Order {$order->id} from {$currentStatus} to {$newStatus}\n";

$order->status_id = $newStatus;
$order->save();

echo "Saved.\n";
echo "Changes: " . json_encode($order->getChanges()) . "\n";
echo "Original(status_id) after save: " . $order->getOriginal('status_id') . "\n";

// Check Observer effect
$log = \App\Models\OrderStatusLog::where('order_id', $order->id)->latest()->first();
if ($log) {
    echo "Log created: From {$log->from_status_id} To {$log->to_status_id}\n";
    if ($log->from_status_id == $log->to_status_id) {
        echo "PROBLEM: from_status_id equals to_status_id!\n";
    }
} else {
    echo "NO LOG CREATED.\n";
}
