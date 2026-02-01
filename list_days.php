<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\BusinessDay;

$today = now()->toDateString();
$days = BusinessDay::where('date', $today)->get();

foreach ($days as $day) {
    echo "--------------------------------------------------\n";
    echo "ID: " . $day->id . "\n";
    echo "Shop ID: " . $day->shop_id . "\n";
    echo "Date: " . $day->date->toDateString() . "\n";
    echo "Open At: " . ($day->open_at ? $day->open_at->toDateTimeString() : 'NULL') . "\n";
    echo "Close At: " . ($day->close_at ? $day->close_at->toDateTimeString() : 'NULL') . "\n";
    echo "Is Open: " . ($day->is_open ? 'YES' : 'NO') . "\n";
    echo "--------------------------------------------------\n";
}
