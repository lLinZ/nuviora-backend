<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Services\ShopifyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use App\Services\Assignment\AssignOrderService;

class ShopifyWebhookController extends Controller
{
    //
    //
    public function handleOrderCreate(Request $request, ShopifyService $shopifyService, AssignOrderService $assignService, $shop_id = null)
    {
        $orderData = $request->all();
        $shop = null;
        if ($shop_id) {
            $shop = \App\Models\Shop::find($shop_id);
        }

        // Si no se pasó shop_id en la URL, intentar resolver por dominio
        if (!$shop) {
            $domain = $request->header('X-Shopify-Shop-Domain');
            if ($domain) {
                $shop = \App\Models\Shop::where('shopify_domain', $domain)->first();
            }
        }

        // 🔒 0. Verificar firma HMAC de Shopify
        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
        $secret = ($shop && $shop->shopify_webhook_secret) 
            ? $shop->shopify_webhook_secret 
            : env('SHOPIFY_WEBHOOK_SECRET');

        $calculatedHmac = base64_encode(
            hash_hmac('sha256', $request->getContent(), $secret, true)
        );

        if (!hash_equals($hmacHeader, $calculatedHmac)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 1️⃣ Guardar/actualizar cliente
        // ⚠️ Mejorado: Si no viene el objeto 'customer' (POS, Guest checkout), intentamos sacar la info de shipping/billing
        $customerData = $orderData['customer'] ?? null;
        $shipping = $orderData['shipping_address'] ?? null;
        $billing = $orderData['billing_address'] ?? null;
        $fallbackSource = $shipping ?? $billing ?? [];

        // Definir ID de cliente (Si no hay ID de Shopify, usamos el ID de la orden como temporal para este "Guest")
        $shopifyCustomerId = $customerData['id'] ?? null;
        $finalCustomerId = $shopifyCustomerId ?? $orderData['id'];

        // Extraer email
        $email = $customerData['email'] ?? $orderData['email'] ?? null;
        
        // Extraer nombres (Prioridad Cliente -> Shipping -> Billing)
        $firstName = $customerData['first_name'] ?? $fallbackSource['first_name'] ?? 'Guest';
        $lastName = $customerData['last_name'] ?? $fallbackSource['last_name'] ?? 'Customer';
        $phone = $customerData['phone'] ?? $orderData['phone'] ?? $shipping['phone'] ?? $billing['phone'] ?? null;

        // Extraer dirección para ciudad/provincia
        $addressSource = $customerData['default_address'] ?? $shipping ?? $billing ?? [];

        $client = Client::updateOrCreate(
            ['customer_id' => $finalCustomerId],
            [
                'customer_number' => $finalCustomerId,
                'first_name'      => $firstName,
                'last_name'       => $lastName,
                'phone'           => $phone,
                'email'           => $email,
                'country_name'    => $addressSource['country'] ?? null,
                'country_code'    => $addressSource['country_code'] ?? null,
                'province'        => $addressSource['province'] ?? null,
                'city'            => $addressSource['province'] ?? null,
                'address1'        => $addressSource['address1'] ?? null,
                'address2'        => $addressSource['address2'] ?? null,
            ]
        );

        // 2️⃣ Guardar/actualizar orden
        // Buscar "ciudad" (que ahora es provincia) en la tabla cities
        $provinceName = $client->province;
        $cityId = null;
        $candidateAgencyId = null;
        if ($provinceName) {
            // Busqueda case-insensitive en tabla cities (que contiene provincias)
            $cityMatch = \App\Models\City::where('name', 'LIKE', trim($provinceName))->first();
            if ($cityMatch) {
                $cityId = $cityMatch->id;
                $candidateAgencyId = $cityMatch->agency_id;
            }
        }

        // Buscar provincia si existe
        $provinceId = null;
        if ($provinceName) {
            $provinceMatch = \App\Models\Province::where('name', 'LIKE', trim($provinceName))->first();
            if ($provinceMatch) {
                $provinceId = $provinceMatch->id;
            }
        }

        // Obtener el status "Nuevo" para órdenes recién creadas
        $nuevoStatus = \App\Models\Status::where('description', 'Nuevo')->first();
        $statusId = $nuevoStatus ? $nuevoStatus->id : 1; // Fallback a 1 si no existe

        $order = Order::updateOrCreate(
            ['order_id' => $orderData['id']],
            [
                'name'                => $orderData['name'],
                'current_total_price' => round($orderData['current_total_price'] ?? $orderData['total_price'] ?? 0),
                'order_number'        => $orderData['order_number'],
                'processed_at'        => $orderData['processed_at']
                    ? Carbon::parse($orderData['processed_at'])->toDateTimeString()
                    : null,
                'currency'            => $orderData['currency'],
                'client_id'           => $client->id,
                'status_id'           => $statusId,
                'shop_id'             => $shop ? $shop->id : null,
                'city_id'             => $cityId,
                'province_id'         => $provinceId,
            ]
        );

        // Auto-asignar agencia si la orden no tiene una y la ciudad/provincia tiene una asignada
        if (!$order->agency_id && $candidateAgencyId) {
            $order->agency_id = $candidateAgencyId;
            $order->save();
        }

        // 3️⃣ Procesar productos de la orden
        foreach ($orderData['line_items'] as $item) {
            // Saltar items sin product_id (ej: productos personalizados, descuentos)
            if (empty($item['product_id'])) {
                \Log::warning("Shopify webhook: Skipping line item without product_id", [
                    'order_id' => $orderData['id'],
                    'item_title' => $item['title'] ?? 'N/A',
                    'item_sku' => $item['sku'] ?? 'N/A'
                ]);
                continue;
            }

            // Obtener imagen desde Shopify API
            $imageUrl = $shopifyService->getProductImage(
                $item['product_id'],
                $item['variant_id'] ?? null
            );

            // Buscar producto por nombre (case-insensitive)
            $productTitle = trim($item['title']);
            $existingProduct = \App\Models\Product::whereRaw('LOWER(title) = ?', [strtolower($productTitle)])->first();

            if ($existingProduct) {
                // Actualizar producto existente
                $existingProduct->update([
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'name'       => $item['name'] ?? null,
                    'price'      => round($item['price']),
                    'sku'        => $item['sku'] ?? null,
                    'image'      => $imageUrl,
                ]);
                $product = $existingProduct;
            } else {
                // Crear nuevo producto
                $product = \App\Models\Product::create([
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'title'      => $productTitle,
                    'name'       => $item['name'] ?? null,
                    'price'      => round($item['price']),
                    'sku'        => $item['sku'] ?? null,
                    'image'      => $imageUrl,
                ]);
            }

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
                    'price'          => round($item['price']),
                    'quantity'       => $item['quantity'],
                    'image'          => $imageUrl,
                    'showable_name'  => $product->showable_name,
                ]
            );
        }

        // 4️⃣ Intento de Auto-Asignación (Round Robin)
        try {
            $assignedAgent = $assignService->assignOne($order);
            if ($assignedAgent) {
                \Log::info("Order #{$order->order_number} auto-assigned to agent: {$assignedAgent->email}");
            } else {
                \Log::info("Order #{$order->order_number} could not be auto-assigned (No roster/Closed business day)");
            }
        } catch (\Exception $e) {
            \Log::error("Error auto-assigning order {$order->id}: " . $e->getMessage());
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
        $value = round($order['total_price'] ?? 0);
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
