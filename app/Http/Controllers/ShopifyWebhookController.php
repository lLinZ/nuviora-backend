<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderProducts;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    //
    public function handleOrderCreate(Request $request)
    {

        // Loguea lo que llega
        Log::info('Webhook recibido', [
            'headers' => $request->headers->all(),
            'body' => $request->getContent(),
        ]);

        // Devuelve respuesta 200 a Shopify
        return response()->json(['status' => 'ok'], 200);
        $data = $request->all();

        // 🔒 Opcional: validar firma de Shopify
        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
        $calculatedHmac = base64_encode(
            hash_hmac('sha256', $request->getContent(), env('SHOPIFY_WEBHOOK_SECRET'), true)
        );
        if (!hash_equals($hmacHeader, $calculatedHmac)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 1️⃣ Guardar/actualizar cliente
        $client = Client::updateOrCreate(
            ['customer_id' => $data['customer']['id']],
            [
                'customer_number' => $data['customer']['id'],
                'first_name' => $data['customer']['first_name'] ?? null,
                'last_name'  => $data['customer']['last_name'] ?? null,
                'phone'      => $data['customer']['phone'] ?? null,
                'email'      => $data['customer']['email'] ?? null,
                'country_name' => $data['customer']['default_address']['country'] ?? null,
                'country_code' => $data['customer']['default_address']['country_code'] ?? null,
                'province'   => $data['customer']['default_address']['province'] ?? null,
                'city'       => $data['customer']['default_address']['city'] ?? null,
                'address1'   => $data['customer']['default_address']['address1'] ?? null,
                'address2'   => $data['customer']['default_address']['address2'] ?? null,
            ]
        );

        // 2️⃣ Guardar/actualizar orden
        $order = Order::updateOrCreate(
            ['order_id' => $data['id']],
            [
                'name'                => $data['name'],
                'order_number'        => $data['order_number'],
                'current_total_price' => $data['current_total_price'],
                'currency'            => $data['currency'],
                'processed_at'        => $data['processed_at'] ?? null,
                'client_id'           => $client->id,
            ]
        );

        // 3️⃣ Procesar productos de la orden
        foreach ($data['line_items'] as $item) {
            // Buscar o crear producto en catálogo
            $product = Product::updateOrCreate(
                ['product_id' => $item['product_id']],
                [
                    'variant_id' => $item['variant_id'] ?? null,
                    'sku'        => $item['sku'] ?? null,
                    'title'      => $item['title'],
                    'name'       => $item['name'] ?? null,
                    'price'      => $item['price'],
                    'image'      => $item['image'] ?? null,
                ]
            );

            // Registrar relación en order_products
            OrderProducts::updateOrCreate(
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
                    'image'          => $item['image'] ?? null,
                ]
            );
        }

        return response()->json(['success' => true], 200);
    }
}
