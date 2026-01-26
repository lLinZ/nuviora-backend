<?php
// simulate_shopify_webhook.php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use App\Models\City;
use App\Models\Order;

// 1. Crear ciudad de prueba "Valencia"
$cityName = 'Valencia';
$city = City::firstOrCreate(
    ['name' => $cityName],
    [
        'state' => 'Carabobo',
        'active' => true,
        'delivery_cost_usd' => 5.00
    ]
);
echo "✅ Ciudad asegurada: {$city->name} (ID: {$city->id})\n";

// 2. Preparar payload de Shopify con esa ciudad
$payload = [
    'id' => 999111222, // ID ficticio
    'name' => '#9999',
    'current_total_price' => '50.00',
    'order_number' => 9999,
    'processed_at' => now()->toIso8601String(),
    'currency' => 'USD',
    'email' => 'test_client@example.com',
    'landing_site' => '/',
    'total_price' => '50.00',
    'customer' => [
        'id' => 888777,
        'first_name' => 'Tester',
        'last_name' => 'Automático',
        'email' => 'test_client@example.com',
        'phone' => '+584121234567',
        'default_address' => [
            'country' => 'Venezuela',
            'country_code' => 'VE',
            'province' => 'Carabobo',
            'city' => 'Valencia', // 👈 Aquí está la clave
            'address1' => 'Av Bolivar',
            'address2' => 'Edif Test',
        ]
    ],
    'line_items' => [
        [
            'product_id' => 123456,
            'variant_id' => 654321,
            'title' => 'Producto Test',
            'name' => 'Producto Test',
            'price' => '25.00',
            'sku' => 'TEST-SKU',
            'quantity' => 2,
        ]
    ]
];

$jsonPayload = json_encode($payload);

// 3. Calcular HMAC Signature
$secret = env('SHOPIFY_WEBHOOK_SECRET');
$hmac = base64_encode(hash_hmac('sha256', $jsonPayload, $secret, true));

echo "🔑 HMAC Generado: {$hmac}\n";

// 4. Enviar Petición POST emulando Shopify
// Usamos el helper de HTTP de Laravel apuntando a localhost, o instanciamos el request internamente.
// Para ser más realistas y probar rutas, haremos una petición HTTP local.
$url = 'http://127.0.0.1:8000/api/shopify/orders/create';

try {
    // Si no tienes el servidor corriendo en el puerto 8000, esto fallará. 
    // Asegúrate de que `php artisan serve` esté activo O usamos el método interno.
    // Usaremos el método interno Request::create para no depender de `serve`.
    
    // URL correcta para creación de órdenes
    $uri = '/api/order/webhook';
    echo "🚀 Enviando request interno a $uri ...\n";
    
    $request = Illuminate\Http\Request::create(
        $uri,
        'POST',
        [],
        [],
        [],
        [
            'HTTP_X_Shopify_Hmac_Sha256' => $hmac,
            'CONTENT_TYPE' => 'application/json'
        ],
        $jsonPayload
    );
    
    $response = $app->handle($request);
    
    echo "📡 Status Code: " . $response->getStatusCode() . "\n";
    echo "📄 Response: " . $response->getContent() . "\n";

    if ($response->getStatusCode() === 200) {
        // 5. Verificar resultado
        $order = Order::where('order_number', 9999)->first();
        if ($order) {
            echo "✅ Orden Encontrada: #{$order->order_number}\n";
            echo "🏙️  City ID en Orden: " . ($order->city_id ?? 'NULL') . "\n";
            echo "🏙️  Esperado: {$city->id}\n";
            
            if ($order->city_id == $city->id) {
                echo "🎉 ÉXITO: La ciudad se asignó correctamente.\n";
            } else {
                echo "❌ FALLO: El ID no coincide.\n";
            }
        } else {
            echo "❌ La orden no se creó en la BD.\n";
        }
    }

} catch (\Exception $e) {
    echo "🔥 Error Excepción: " . $e->getMessage() . "\n";
}
