<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Order;
use App\Models\City;

$order = Order::find(1);
if (!$order) {
    echo "Order not found\n";
    exit;
}

$order->client->update(['city' => 'Caracas']);
$order->update(['agency_id' => null, 'city_id' => null]);

echo "Setup complete: Order 1 client city is 'Caracas' and order agency is cleared.\n";
