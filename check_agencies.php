<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$orders = \App\Models\Order::whereHas('status', function($q) {
    $q->where('description', '=', 'Entregado');
})->whereNotNull('agency_id')->get();

echo "Found " . $orders->count() . " delivered orders with agency.\n";
foreach ($orders as $o) {
    echo "Order: {$o->name} | AgencyID: {$o->agency_id}\n";
}
