<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\BusinessDay;
use Carbon\Carbon;

// Mock request data
$shopId = 999; 
$today = now()->toDateString();

// Cleanup
BusinessDay::where('shop_id', $shopId)->delete();

echo "Testing for Shop ID: $shopId, Date: $today\n";

// replicate controller logic
$day = BusinessDay::firstOrCreate(['date' => $today, 'shop_id' => $shopId]);

echo "Created/Found Day ID: " . $day->id . "\n";
echo "Open At: " . ($day->open_at ? $day->open_at->toDateTimeString() : 'NULL') . "\n";
echo "Close At: " . ($day->close_at ? $day->close_at->toDateTimeString() : 'NULL') . "\n";

$isOpen = $day->is_open;
echo "Is Open (Accessor): " . ($isOpen ? 'TRUE' : 'FALSE') . "\n";

$json = json_encode([
    'is_open' => $day->is_open,
    'open_at' => optional($day->open_at)->toDateTimeString(),
]);

echo "JSON Output: " . $json . "\n";
