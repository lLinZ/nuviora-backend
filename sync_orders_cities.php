<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Order;
use App\Models\City;

$orders = Order::with('client')->get();
$cities = City::all();

$updatedCount = 0;
$noMatchCount = 0;

foreach ($orders as $order) {
    if (!$order->client || !$order->client->city) {
        $noMatchCount++;
        continue;
    }

    $clientCityName = strtoupper(trim($order->client->city));
    
    $match = $cities->first(function($city) use ($clientCityName) {
        return strtoupper(trim($city->name)) === $clientCityName;
    });

    if ($match) {
        $order->city_id = $match->id;
        // Optionally assign agency and cost if not already set or to refresh them
        if (!$order->agency_id) {
            $order->agency_id = $match->agency_id;
        }
        if (!$order->delivery_cost) {
            $order->delivery_cost = $match->delivery_cost_usd;
        }
        $order->save();
        $updatedCount++;
    } else {
        $noMatchCount++;
    }
}

echo "Synchronization complete!\n";
echo "Orders updated: $updatedCount\n";
echo "Orders with no match/no city: $noMatchCount\n";
