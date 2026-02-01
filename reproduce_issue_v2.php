<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\BusinessDay;
use Carbon\Carbon;

echo "--- Manual Instance Check ---\n";
$d = new BusinessDay();
$d->open_at = null;
$d->close_at = null;
echo "New Instance (fresh): Is Open? " . ($d->is_open ? 'YES' : 'NO') . "\n";

$d->open_at = now();
echo "Instance with open_at: Is Open? " . ($d->is_open ? 'YES' : 'NO') . "\n";

echo "\n--- Database Check ---\n";
$today = now()->toDateString();
echo "Today is: $today\n";

$days = BusinessDay::where('date', $today)->get();
echo "Found " . $days->count() . " records for today.\n";

foreach ($days as $day) {
    echo "ID: {$day->id}, ShopID: {$day->shop_id}, OpenAt: " . ($day->open_at ?? 'NULL') . ", CloseAt: " . ($day->close_at ?? 'NULL') . "\n";
    echo "Accessor is_open: " . ($day->is_open ? 'YES' : 'NO') . "\n";
    echo "Raw attributes: " . json_encode($day->getAttributes()) . "\n";
}
