<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class ShopifyWebhookController extends Controller
{
    //
    public function handleOrderCreate(Request $request, ShopifyService $shopifyService)
    {
        $orderData = $request->all();

        // 🔒 0. Verificar firma HMAC de Shopify
        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
        $calculatedHmac = base64_encode(
            hash_hmac('sha256', $request->getContent(), env('SHOPIFY_WEBHOOK_SECRET'), true)
        );

        if (!hash_equals($hmacHeader, $calculatedHmac)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 1️⃣ Guardar/actualizar cliente
        $client = Client::updateOrCreate(
            ['customer_id' => $orderData['customer']['id']],
            [
                'customer_number' => $orderData['customer']['id'],
                'first_name'      => $orderData['customer']['first_name'] ?? null,
                'last_name'       => $orderData['customer']['last_name'] ?? null,
                'phone'           => $orderData['customer']['phone'] ?? null,
                'email'           => $orderData['customer']['email'] ?? null,
                'country_name'    => $orderData['customer']['default_address']['country'] ?? null,
                'country_code'    => $orderData['customer']['default_address']['country_code'] ?? null,
                'province'        => $orderData['customer']['default_address']['province'] ?? null,
                'city'            => $orderData['customer']['default_address']['city'] ?? null,
                'address1'        => $orderData['customer']['default_address']['address1'] ?? null,
                'address2'        => $orderData['customer']['default_address']['address2'] ?? null,
            ]
        );

        // 2️⃣ Guardar/actualizar orden
        $order = Order::updateOrCreate(
            ['order_id' => $orderData['id']],
            [
                'name'                => $orderData['name'],
                'current_total_price' => $orderData['current_total_price'],
                'order_number'        => $orderData['order_number'],
                'processed_at'        => $orderData['processed_at'] ?? null,
                'currency'            => $orderData['currency'],
                'client_id'           => $client->id,
            ]
        );

        // 3️⃣ Procesar productos de la orden
        foreach ($orderData['line_items'] as $item) {
            // Obtener imagen desde Shopify API
            $imageUrl = $shopifyService->getProductImage(
                $item['product_id'],
                $item['variant_id'] ?? null
            );

            // Crear/actualizar producto
            $product = Product::updateOrCreate(
                ['product_id' => $item['product_id']],
                [
                    'variant_id' => $item['variant_id'] ?? null,
                    'title'      => $item['title'],
                    'name'       => $item['name'] ?? null,
                    'price'      => $item['price'],
                    'sku'        => $item['sku'] ?? null,
                    'image'      => $imageUrl,
                ]
            );

            // Relación en OrderProducts (evita duplicados)
            OrderProduct::updateOrCreate(
                [
                    'order_id'   => $order->id,
                    'product_id' => $product->id,
                ],
                [
                    'product_number' => $product->product_id,
                    'title'          => $item['title'],
                    'name'           => $item['name'] ?? null,
                    'price'          => $item['price'],
                    'quantity'       => $item['quantity'],
                    'image'          => $imageUrl,
                ]
            );
        }

        return response()->json(['success' => true], 200);
    }
    public function orderCreated(Request $request)
    {
        $pixelId = Config::get('services.facebook.pixel_id');
        $accessToken = Config::get('services.facebook.access_token');

        $order = $request->all();

        // Generamos un event_id único (puede ser el ID de la orden)
        $eventId = 'order_' . $order['id'];

        // Extraemos datos del cliente
        $email = $order['email'] ?? null;
        $phone = $order['phone'] ?? null;
        $firstName = $order['customer']['first_name'] ?? null;
        $lastName = $order['customer']['last_name'] ?? null;

        // Datos custom de la orden
        $value = $order['total_price'] ?? 0;
        $currency = $order['currency'] ?? 'USD';
        $contentIds = collect($order['line_items'])->pluck('product_id')->map(fn($id) => (string)$id)->toArray();

        // Payload para Facebook CAPI
        $payload = [
            'data' => [
                [
                    'event_name'       => 'Purchase',
                    'event_time'       => now()->timestamp,
                    'event_source_url' => $order['landing_site'] ?? null,
                    'event_id'         => $eventId,
                    'action_source'    => 'website',
                    'user_data'        => [
                        'em' => $email ? hash('sha256', strtolower(trim($email))) : null,
                        'ph' => $phone ? hash('sha256', preg_replace('/\D+/', '', $phone)) : null,
                        'fn' => $firstName ? hash('sha256', strtolower(trim($firstName))) : null,
                        'ln' => $lastName ? hash('sha256', strtolower(trim($lastName))) : null,
                        'client_user_agent' => $request->header('User-Agent'),
                        'ip_address'        => $request->ip(),
                    ],
                    'custom_data' => [
                        'currency'      => $currency,
                        'value'         => $value,
                        'content_type'  => 'product',
                        'content_ids'   => $contentIds,
                    ],
                ],
            ],
            'access_token' => $accessToken,
        ];

        // Enviar evento a Facebook
        $response = Http::post("https://graph.facebook.com/v17.0/{$pixelId}/events", $payload);

        return response()->json([
            'status' => 'ok',
            'facebook_response' => $response->json(),
        ]);
    }
}
