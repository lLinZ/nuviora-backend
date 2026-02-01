<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Shop;
use App\Models\BusinessDay;

echo "--- SHOPS ---\n";
foreach (Shop::all() as $shop) {
    echo "ID: {$shop->id}, Name: {$shop->name}\n";
}

echo "\n--- BUSINESS DAYS ---\n";
foreach (BusinessDay::all() as $bd) {
    echo "ID: {$bd->id}, ShopID: {$bd->shop_id}, Date: {$bd->date}, OpenAt: {$bd->open_at}\n";
}
