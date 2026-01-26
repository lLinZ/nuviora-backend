<?php
use App\Models\Order;
use App\Services\CommissionService;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$orders = Order::whereHas('status', function($q) {
    $q->where('description', '=', 'Entregado');
})->get();

$service = app(CommissionService::class);

foreach ($orders as $order) {
    $service->generateForDeliveredOrder($order);
    echo "Synced Order #{$order->name}\n";
}
