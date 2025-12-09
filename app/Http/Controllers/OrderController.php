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
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|string',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.rate' => 'nullable|numeric|min:0',
        ]);

        // 1. Eliminar pagos anteriores (estrategia simple: borrar y crear nuevos)
        $order->payments()->delete();

        // 2. Obtener las tasas de cambio activas
        $activeCurrencies = \App\Models\Currency::whereHas('status', function($q) {
            $q->where('description', 'Activo');
        })->get()->keyBy('description');

        $usdRate = $activeCurrencies->get('bcv_usd')?->value;
        $eurRate = $activeCurrencies->get('euro')?->value;
        $binanceUsdRate = $activeCurrencies->get('binance_usd')?->value;

        // 3. Crear nuevos pagos con las tasas actuales
        foreach ($request->payments as $paymentData) {
            $order->payments()->create([
                'method' => $paymentData['method'],
                'amount' => $paymentData['amount'],
                // Si es Bs, guardamos la tasa. Si no, null.
                'rate'   => ($paymentData['method'] === 'BOLIVARES_TRANSFERENCIA' || $paymentData['method'] === 'BOLIVARES_EFECTIVO') 
                            ? ($paymentData['rate'] ?? null) 
                            : null,
                // Tasas de cambio del día
                'usd_rate' => $usdRate,
                'eur_rate' => $eurRate,
                'binance_usd_rate' => $binanceUsdRate,
            ]);
        }

        // recargamos relaciones
        $order->load(['client', 'agent', 'status', 'products', 'updates.user', 'payments']);

        return response()->json([
            'status' => true,
            'message' => 'Métodos de pago actualizados',
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
            'payments', // 👈 incluimos pagos
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
                'payments'             => $order->payments, // 👈 enviamos al front
                'payment_receipt'      => $order->payment_receipt,
            ]
        ]);
    }
    public function updateStatus(Request $request, Order $order, CommissionService $commissionService)
    {
        $request->validate([
            'status_id' => 'required|exists:statuses,id',
        ]);

        // Buscamos el status "Entregado"
        $statusEntregado = Status::where('description', 'Entregado')->first();

        // Validar que existe comprobante de pago si se intenta cambiar a Entregado
        if ($statusEntregado && (int) $statusEntregado->id === (int) $request->status_id) {
            if (empty($order->payment_receipt)) {
                return response()->json([
                    'status' => false,
                    'message' => 'No se puede marcar como entregado sin un comprobante de pago',
                ], 422);
            }
        }

        $oldStatusId = $order->status_id;

        $order->status_id = $request->status_id;
        $order->save();

        $order->load(['status', 'agent', 'deliverer', 'client', 'products']);

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
                    'is_upsell'  => false,
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

        // 4️⃣ Restaurar el total incluyendo upsells
        $upsellTotal = $order->products()->where('is_upsell', true)->get()->sum(fn($p) => $p->price * $p->quantity);
        if ($upsellTotal > 0) {
            $order->current_total_price += $upsellTotal;
            $order->save();
        }

        return response()->json(['success' => true], 200);
    }
    public function addLocation(Request $request, Order $order)
    {
        $user = Auth::user();
        $new_order = Order::with([
            'client',
            'agent',
            'status',
            'products.product',   // 👈 importante
            'updates.user',
            'cancellations.user',
        ])->findOrFail($order->id);
        $location_url = $request->location;
        try {
            if ($user->role?->description == 'Vendedor' || $user->role?->description == 'Gerente' || $user->role?->description == 'Admin') {
                $new_order->location = $location_url;
                $new_order->save();
                return response()->json(['status' => true, 'data' => $new_order, 'message' => 'Ubicacion añadida exitosamente'], 200);
            } else {
                return response()->json(['status' => false], 403);
            }
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json(['status' => false, 'msg' => $th->getMessage()], 400);
        }
    }
    // Resto de métodos resource (vacíos por ahora)
    public function index(Request $request)
    {
        $user = Auth::user();
        $perPage = (int) $request->get('per_page', 50);

        $query = Order::with(['client', 'agent', 'deliverer', 'status'])->latest('id');

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
                    ->orWhereBetween('created_at', [$yesterdayStart, $now]);
            });

            // Nota: no aceptamos filtros extra desde el front de vendedor (se ignoran)
        } elseif ($role === 'Repartidor') {
            // Ventana: HOY + AYER (según timezone de app)
            $yesterdayStart = now()->subDay()->startOfDay();
            $now = now();

            $query->where(function ($q) use ($user, $yesterdayStart, $now) {
                // 1) Órdenes asignadas a ese vendedor
                $q->where('deliverer_id', $user->id)
                    // 2) Órdenes creadas hoy o ayer (aunque no estén asignadas a él)
                    ->orWhereBetween('created_at', [$yesterdayStart, $now]);
            });
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
    public function addUpsell(Request $request, Order $order)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
        ]);

        $product = Product::findOrFail($request->product_id);

        OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_number' => $product->product_id,
            'title' => $product->title,
            'name' => $product->name,
            'price' => $request->price,
            'quantity' => $request->quantity,
            'image' => $product->image,
            'is_upsell' => true,
            'upsell_user_id' => auth()->id(),
        ]);

        // Update total
        $upsellAmount = $request->price * $request->quantity;
        $order->current_total_price += $upsellAmount;
        $order->save();

        return response()->json([
            'status' => true,
            'message' => 'Upsell agregado correctamente',
            'order' => $order->load('products.product', 'client', 'status', 'agent')
        ]);
    }

    public function removeUpsell(Order $order, $itemId)
    {
        $item = OrderProduct::where('order_id', $order->id)->where('id', $itemId)->firstOrFail();

        if (!$item->is_upsell) {
            return response()->json(['status' => false, 'message' => 'No es un upsell'], 400);
        }

        $deduction = $item->price * $item->quantity;
        $item->delete();

        // Update total
        $order->current_total_price -= $deduction;
        $order->save();

        return response()->json([
            'status' => true,
            'message' => 'Upsell eliminado correctamente',
            'order' => $order->load('products.product', 'client', 'status', 'agent')
        ]);
    }

    public function create() {}
    public function store(Request $request) {}
    public function edit(Order $order) {}
    public function destroy(Order $order) {}
}
