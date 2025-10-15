<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Client;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\Status;
use App\Models\User;
use App\Services\ShopifyService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function updateStatus(Request $request, Order $order)
    {
        $request->validate([
            'status_id' => 'required|exists:statuses,id'
        ]);

        $status = Status::find($request->status_id);

        $order->status_id = $status->id;
        $order->save();

        $newOrder = Order::with('status', 'client', 'agent')->find($order->id);
        return response()->json([
            'status' => true,
            'message' => 'Estado actualizado correctamente',
            'order' => $newOrder
        ]);
    }
    public function getOrderProducts($orderId)
    {
        $order = Order::with('products.product')->findOrFail($orderId);

        return response()->json([
            'order_id'   => $order->id,
            'order_name' => $order->name,
            'products'   => $order->products->map(function ($op) {
                return [
                    'product_id'   => $op->product_id,
                    'shopify_id'   => $op->product_number,
                    'title'        => $op->title,
                    'name'         => $op->name,
                    'sku'          => $op->product->sku ?? null,
                    'price'        => $op->price,
                    'quantity'     => $op->quantity,
                    'image'        => $op->image,
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

    // Resto de métodos resource (vacíos por ahora)
    public function index(Request $request)
    {
        $perPage = (int) ($request->get('per_page', 50));

        $query = Order::with(['client', 'agent', 'status'])
            ->latest('id');

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }

        $orders = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'data'   => $orders->items(),
            'meta'   => [
                'current_page' => $orders->currentPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
                'last_page'    => $orders->lastPage(),
            ],
        ]);
    }

    // GET /agents/{agent}/orders
    public function byAgent(User $agent, Request $request)
    {
        $perPage = (int) ($request->get('per_page', 50));

        $orders = Order::with(['client', 'agent', 'status'])
            ->where('agent_id', $agent->id)
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'data'   => $orders->items(),
            'meta'   => [
                'current_page' => $orders->currentPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
                'last_page'    => $orders->lastPage(),
            ],
        ]);
    }
    public function assignAgent(Request $request, Order $order)
    {
        $request->validate([
            'agent_id' => 'required|exists:users,id',
        ]);

        $agent = User::findOrFail($request->agent_id);

        if ($agent->role->description !== 'Vendedor') {
            return response()->json([
                'status' => false,
                'message' => 'El usuario seleccionado no es un vendedor válido'
            ], 422);
        }

        // Buscar el status "Asignado a vendedora"
        $statusId = Status::where('description', 'Asignado a vendedora')->first()?->id;

        $order->update([
            'agent_id' => $agent->id,
            'status_id' => $statusId
        ]);

        return response()->json([
            'status' => true,
            'order' => $order->load('agent', 'status', 'client'),
        ]);
    }
    public function create() {}
    public function store(Request $request) {}
    public function show(Order $order) {}
    public function edit(Order $order) {}
    public function update(Request $request, Order $order) {}
    public function destroy(Order $order) {}
}
