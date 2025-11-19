<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Client;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\Status;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    public function products(Order $order) {}
    public function updatePayment(Request $request, Order $order)
    {
        $request->validate([
            'payment_method' => 'required|in:DOLARES_EFECTIVO,BOLIVARES_TRANSFERENCIA,BINANCE_DOLARES,ZELLE_DOLARES',
            'payment_rate'   => 'nullable|numeric|min:0',
        ]);

        $order->payment_method = $request->payment_method;

        // Sólo exigimos tasa si el pago fue en Bs
        if ($request->payment_method === 'BOLIVARES_TRANSFERENCIA') {
            $request->validate([
                'payment_rate' => 'required|numeric|min:0.0001',
            ]);
            $order->payment_rate = $request->payment_rate;
        } else {
            // para pagos en USD no necesitamos tasa
            $order->payment_rate = null;
        }

        $order->save();

        // recargamos relaciones para que el front tenga todo actualizado
        $order->load(['client', 'agent', 'status', 'products', 'updates.user']);

        return response()->json([
            'status' => true,
            'message' => 'Método de pago actualizado',
            'order' => $order,
        ]);
    }
    public function show($id)
    {
        $order = \App\Models\Order::with([
            'client',
            'agent',
            'status',
            'products.product',   // 👈 importante
            'updates.user',
            'cancellations.user',
        ])->findOrFail($id);

        // Si quieres devolver items “planchados” (recomendado para front):
        $items = $order->products->map(function ($op) {
            return [
                'id'        => $op->id,
                'product_id' => $op->product_id,
                'title'     => $op->title ?? ($op->product->title ?? $op->product->name ?? 'Producto'),
                'sku'       => $op->product->sku ?? null,
                'image'     => $op->image ?? $op->product->image ?? null,
                'price'     => (float) $op->price,
                'quantity'  => (int) $op->quantity,
                'subtotal'  => (float) $op->price * (int) $op->quantity,
            ];
        });

        // Total calculado desde los items (si quieres validar el current_total_price)
        $computedTotal = $items->sum('subtotal');

        return response()->json([
            'status' => true,
            'order'  => [
                'id'                   => $order->id,
                'name'                 => $order->name,
                'currency'             => $order->currency,
                'current_total_price'  => $order->current_total_price ?? $computedTotal,
                'client'               => $order->client,
                'agent'                => $order->agent,
                'status'               => $order->status,
                'products'             => $items, // 👈 ya listo para el front
                'updates'              => $order->updates,
                'cancellations'        => $order->cancellations,
            ]
        ]);
    }
    public function updateStatus(Request $request, Order $order, CommissionService $commissionService)
    {
        $request->validate([
            'status_id' => 'required|exists:statuses,id',
        ]);

        $oldStatusId = $order->status_id;

        $order->status_id = $request->status_id;
        $order->save();

        $order->load(['status', 'agent', 'deliverer', 'client', 'products']);

        // Buscamos el status "Entregado"
        $statusEntregado = Status::where('description', 'Entregado')->first();

        if ($statusEntregado && (int) $statusEntregado->id === (int) $order->status_id) {
            // Solo generamos ganancias cuando CAMBIA a Entregado
            if ($oldStatusId !== $order->status_id) {
                $commissionService->generateForDeliveredOrder($order);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Estado actualizado correctamente',
            'order' => $order,
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
        $user = Auth::user();
        $perPage = (int) $request->get('per_page', 50);

        $query = Order::with(['client', 'agent', 'status'])->latest('id');

        // 🔒 Reglas por rol
        $role = $user->role?->description; // "Vendedor", "Gerente", "Admin", etc.

        if ($role === 'Vendedor') {
            // Ventana: HOY + AYER (según timezone de app)
            $yesterdayStart = now()->subDay()->startOfDay();
            $now = now();

            $query->where(function ($q) use ($user, $yesterdayStart, $now) {
                // 1) Órdenes asignadas a ese vendedor
                $q->where('agent_id', $user->id)
                    // 2) Órdenes creadas hoy o ayer (aunque no estén asignadas a él)
                    ->andWhereBetween('created_at', [$yesterdayStart, $now]);
            });

            // Nota: no aceptamos filtros extra desde el front de vendedor (se ignoran)
        } else {
            // Gerente/Admin → filtros opcionales
            if ($request->filled('agent_id')) {
                $query->where('agent_id', $request->agent_id);
            }
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
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
    public function edit(Order $order) {}
    public function destroy(Order $order) {}
}
