<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Order;
use Carbon\Carbon;

$agency = User::whereHas('role', fn($q) => $q->where('description', 'agencia'))->first();
if (!$agency) {
    echo "No agency found\n";
    exit;
}

$from = Carbon::now()->startOfMonth();
$to = Carbon::now()->endOfDay();

$allOrders = Order::with('status')->where('agency_id', $agency->id)->get();

echo "--- ALL ORDERS Agency ID " . $agency->id . " ---\n";
foreach ($allOrders as $o) {
    echo "ID: {$o->id} | Name: {$o->name} | Status: {$o->status->description} | Shipped: " . ($o->was_shipped?'YES':'NO') . " | Updated: {$o->updated_at}\n";
}
