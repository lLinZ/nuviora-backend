<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Order;
use App\Http\Controllers\OrderController;
use Illuminate\Http\Request;
use App\Services\CommissionService;

$order = Order::with('client')->find(1);
$request = new Request(['status_id' => 22]); // ID for 'Asignar a agencia'

$controller = new OrderController();
$commissionService = new CommissionService();

// Mock Auth if needed (the controller uses Auth::user() in some parts)
$user = \App\Models\User::first(); // Just use the first available user (Admin usually)
auth()->login($user);

$response = $controller->updateStatus($request, $order, $commissionService);

echo "Response Status: " . ($response->getData()->status ? 'Success' : 'Failure') . "\n";
echo "Message: " . $response->getData()->message . "\n";
$updatedOrder = Order::find(1);
echo "Assigned Agency ID: " . ($updatedOrder->agency_id ?? 'NULL') . "\n";
echo "City ID: " . ($updatedOrder->city_id ?? 'NULL') . "\n";
echo "Delivery Cost: " . ($updatedOrder->delivery_cost ?? 'NULL') . "\n";
