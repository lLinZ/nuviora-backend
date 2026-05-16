<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\External\ExternalWhatsAppController;
use App\Models\Order;
use App\Models\Client;
use App\Models\Status;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

echo "--- RUNNING STRICT CHECK-WINDOW PRIORITY VERIFICATION ---\n\n";

DB::beginTransaction();

try {
    // 1. Setup shop (look up or create)
    $shop = Shop::first();
    if (!$shop) {
        $shop = Shop::create([
            'name' => 'Test Shop',
            'slug' => 'test-shop',
            'shopify_domain' => 'test-shop.myshopify.com',
            'access_token' => 'shpat_testtoken',
        ]);
    }
    $shopId = $shop->id;

    // 2. Setup statuses (look up existing ones to avoid unique constraint violations)
    $statusActive = Status::where('description', 'Pendiente')->first();
    if (!$statusActive) {
        $statusActive = Status::create(['description' => 'Pendiente']);
    }
    
    $statusInactive = Status::where('description', 'Entregado')->first();
    if (!$statusInactive) {
        $statusInactive = Status::create(['description' => 'Entregado']);
    }

    $client = Client::create([
        'phone' => '+584121234567',
        'first_name' => 'Fran',
        'last_name' => 'Tester',
        'customer_id' => 998877,
    ]);

    $order1 = Order::create([
        'shop_id' => $shopId,
        'client_id' => $client->id,
        'status_id' => $statusActive->id,
        'order_id' => 123456789,
        'order_number' => '1001',
        'current_total_price' => 150.00,
        'ves_price' => 6000.00,
    ]);

    $order2 = Order::create([
        'shop_id' => $shopId,
        'client_id' => $client->id,
        'status_id' => $statusInactive->id,
        'order_id' => 456789012,
        'order_number' => '1002',
        'current_total_price' => 200.00,
        'ves_price' => 8000.00,
    ]);

    echo "Successfully seeded test data in transaction:\n";
    echo "Shop ID: {$shopId}, Name: {$shop->name}\n";
    echo "Client ID: {$client->id}, Phone: {$client->phone}\n";
    echo "Active Order 1: ID {$order1->id}, Shopify ID: 123456789, Number: 1001\n";
    echo "Inactive Order 2: ID {$order2->id}, Shopify ID: 456789012, Number: 1002\n\n";

    $controller = new ExternalWhatsAppController();

    function testCheckWindow($controller, $label, $params) {
        echo "TEST CASE: {$label}\n";
        echo "Params: " . json_encode($params) . "\n";
        $request = new Request($params);
        $response = $controller->checkWindow($request);
        echo "Response Status: " . $response->getStatusCode() . "\n";
        $body = json_decode($response->getContent(), true);
        if (isset($body['success']) && $body['success']) {
            echo "Result: SUCCESS. Resolved Client ID: " . $body['client']['id'] . ", Order ID: " . ($body['order'] ? $body['order']['internal_id'] : 'None') . "\n";
        } else {
            echo "Result: FAILED. Message: " . ($body['message'] ?? 'N/A') . "\n";
        }
        echo "-----------------------------------------\n\n";
    }

    // Test Case 1: Match by internal_id
    testCheckWindow($controller, "Match exactly by internal_id (Order 1)", [
        'internal_id' => $order1->id,
        'shop_id' => $shopId,
        'client_id' => $client->id
    ]);

    // Test Case 2: Match by internal_id with mismatched client_id (should fail)
    testCheckWindow($controller, "Mismatched Client Validation (Order 1 with client 999)", [
        'internal_id' => $order1->id,
        'shop_id' => $shopId,
        'client_id' => 999
    ]);

    // Test Case 3: Match by shop_id + order_id
    testCheckWindow($controller, "Match by shop_id + Shopify order_id", [
        'shop_id' => $shopId,
        'order_id' => 123456789
    ]);

    // Test Case 4: Match by shop_id + order_number
    testCheckWindow($controller, "Match by shop_id + order_number", [
        'shop_id' => $shopId,
        'order_number' => '1001'
    ]);

    // Test Case 5: Match by client_id + phone + last active order (should resolve active Order 1, not inactive Order 2)
    testCheckWindow($controller, "Match last active order using client_id + phone", [
        'client_id' => $client->id,
        'phone' => '584121234567',
        'shop_id' => $shopId
    ]);

    // Test Case 6: Cross-tenant / Shop security check (should fail)
    testCheckWindow($controller, "Prevent cross-shop lookup", [
        'internal_id' => $order1->id,
        'shop_id' => $shopId + 1 // Mismatched shop
    ]);

    // Test Case 7: Resolving ambiguity (create a second active order for same client & shop, should trigger ambiguity check)
    $order3 = Order::create([
        'shop_id' => $shopId,
        'client_id' => $client->id,
        'status_id' => $statusActive->id,
        'order_id' => 789012345,
        'order_number' => '1003',
    ]);
    echo "Seeded second active order (Order 3) to test ambiguity detection...\n\n";

    testCheckWindow($controller, "Ambiguity check for client with multiple active orders", [
        'client_id' => $client->id,
        'phone' => '584121234567',
        'shop_id' => $shopId
    ]);

} catch (\Exception $e) {
    echo "Exception occurred: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    DB::rollBack();
    echo "Seeded database records rolled back successfully. Environment clean!\n";
}
