<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$order = \App\Models\Order::with('products')->latest()->first();
echo "Order ID: " . $order->id . "\n";
echo "Order Name: " . $order->name . "\n";
$payload = \App\Models\OrderActivityLog::where('order_id', $order->id)->where('description', 'like', '%Webhook%')->first();
if ($payload) {
    echo "Webhook exists\n";
}

foreach($order->products as $p) {
    echo "Product title: " . $p->title . "\n";
    echo "Product name: " . $p->name . "\n";
    echo "Product showable_name: " . $p->showable_name . "\n";
}
