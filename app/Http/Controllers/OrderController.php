<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Client;
use App\Models\OrderProducts;
use App\Models\Product;
use App\Services\ShopifyService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function getOrderProducts($orderId)
    {
        $order = Order::with(['products.product'])->findOrFail($orderId);

        return response()->json([
            'order_id'   => $order->id,
            'order_name' => $order->name,
            'products'   => $order->products->map(function ($op) {
                return [
                    'product_id'   => $op->product->id,
                    'shopify_id'   => $op->product->product_id,
                    'title'        => $op->product->title,
                    'name'         => $op->product->name,
                    'sku'          => $op->product->sku,
                    'price'        => $op->price,
                    'quantity'     => $op->quantity,
                    'image'        => $op->product->image,
                ];
            }),
        ]);
    }
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
                    'image'          => $imageUrl,
                ]
            );
        }

        return response()->json(['success' => true], 200);
    }

    // Resto de métodos resource (vacíos por ahora)
    public function index() {}
    public function create() {}
    public function store(Request $request) {}
    public function show(Order $order) {}
    public function edit(Order $order) {}
    public function update(Request $request, Order $order) {}
    public function destroy(Order $order) {}
}
