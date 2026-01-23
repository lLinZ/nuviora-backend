<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Client;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\Status;
use App\Models\User;
use App\Models\City;
use App\Services\CommissionService;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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
            $q->where('description', '=', 'Activo');
        })->get(['*'])->keyBy('description');

        $usdRate = $activeCurrencies->get('bcv_usd')?->value;
        $eurRate = $activeCurrencies->get('bcv_eur')?->value;
        $binanceUsdRate = $activeCurrencies->get('binance_usd')?->value;

        // 3. Crear nuevos pagos con las tasas actuales e ir sumando el total
        $totalPaid = 0;
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
            $totalPaid += (float) $paymentData['amount'];
        }

        // 4. Sincronizar automáticamente el resumen de vuelto en la tabla órdenes
        $currentTotal = (float) $order->current_total_price;
        $changeAmount = $totalPaid - $currentTotal;

        $orderData = [
            'cash_received' => $totalPaid,
            'change_amount' => $changeAmount > 0 ? $changeAmount : 0,
        ];

        // Si el pago es exacto o falta dinero, limpiamos cualquier rastro de gestión de vuelto previa
        if ($changeAmount <= 0.005) {
            $orderData['change_covered_by'] = null;
            $orderData['change_amount_company'] = null;
            $orderData['change_amount_agency'] = null;
            $orderData['change_method_company'] = null;
            $orderData['change_method_agency'] = null;
        }

        $order->update($orderData);

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
            'products.upsellUser', // 👈 importante para mostrar quien hizo el upsell
            'updates.user',
            'cancellations.user',
            'deliveryReviews', // 👈 enviamos al front
            'locationReviews', // 👈 enviamos al front
            'rejectionReviews', // 👈 enviamos al front
            'payments', // 👈 incluimos pagos
            'agency', // 👈 incluimos agencia
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
                'is_upsell' => (bool) $op->is_upsell,
                'upsell_user_name' => $op->upsellUser?->names ?? null, // 👈 Nombre para el front
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
                'delivery_reviews'     => $order->deliveryReviews, 
                'location_reviews'     => $order->locationReviews,
                'rejection_reviews'    => $order->rejectionReviews,
                'payments'             => $order->payments, 
                'payment_receipt'      => $order->payment_receipt,
                'reminder_at'          => $order->reminder_at,
                'binance_rate'         => \App\Models\Setting::where('key', '=', 'rate_binance_usd')->first()?->value ?? 0,
                'bcv_rate'             => \App\Models\Setting::where('key', '=', 'rate_bcv_usd')->first()?->value ?? 0,
                'agency'               => $order->agency, // 👈 incluimos agencia
                'novedad_type'         => $order->novedad_type,
                'novedad_description'  => $order->novedad_description,
                'novedad_resolution'   => $order->novedad_resolution,
                'location'             => $order->location,
                'cash_received'        => $order->cash_received,
                'change_amount'        => $order->change_amount,
                'change_covered_by'    => $order->change_covered_by,
                'change_amount_company' => $order->change_amount_company,
                'change_amount_agency'  => $order->change_amount_agency,
                'change_method_company' => $order->change_method_company,
                'change_method_agency'  => $order->change_method_agency,
                'change_rate'           => $order->change_rate,
            ]
        ]);
    }
    public function updateStatus(Request $request, Order $order, CommissionService $commissionService)
    {
        try {
            $request->validate([
                'status_id' => 'required|exists:statuses,id',
            ]);

            // Buscamos status "Entregado" para validación de comprobante
            $statusEntregado = Status::where('description', '=', 'Entregado')->first();
            $statusCambioUbicacion = Status::where('description', '=', 'Cambio de ubicacion')->first();

        // 1. Validar que existe comprobante de pago si se intenta cambiar a Entregado
        if ($statusEntregado && (int) $statusEntregado->id === (int) $request->status_id) {
            if (empty($order->payment_receipt)) {
                return response()->json([
                    'status' => false,
                    'message' => 'No se puede marcar como entregado sin un comprobante de pago',
                ], 422);
            }

            // Validar efectivo/vueltos solo si el método de pago es EFECTIVO
            if ($order->payment_method === 'EFECTIVO' || $order->payments()->where('method', '=', 'EFECTIVO')->exists()) {
                $request->validate([
                    'cash_received' => 'required|numeric|min:0',
                    'change_covered_by' => 'required|in:agency,company,partial',
                ]);
                
                $order->cash_received = $request->cash_received;
                $order->change_amount = max(0, $request->cash_received - $order->current_total_price);
                $order->change_covered_by = $request->change_covered_by;

                if ($request->change_covered_by === 'company') {
                    $order->change_amount_company = $order->change_amount;
                    $order->change_amount_agency = 0;
                } elseif ($request->change_covered_by === 'agency') {
                    $order->change_amount_agency = $order->change_amount;
                    $order->change_amount_company = 0;
                } else {
                    $request->validate([
                        'change_amount_company' => 'required|numeric|min:0',
                        'change_amount_agency' => 'required|numeric|min:0',
                    ]);
                    $order->change_amount_company = $request->change_amount_company;
                    $order->change_amount_agency = $request->change_amount_agency;
                }
            }
        }

        // 2. 🛑 INTERCEPCIÓN PARA APROBACIÓN DE CAMBIO DE UBICACION 🛑
        if ($statusCambioUbicacion && (int) $statusCambioUbicacion->id === (int) $request->status_id) {
            $userRole = Auth::user()->role?->description;
            if (!in_array($userRole, ['Gerente', 'Admin', 'Agencia'])) {
                
                // Verificar si ya existe una pendiente
                $existingPending = $order->locationReviews()->where('status', '=', 'pending')->first();
                if ($existingPending) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Ya existe una solicitud de aprobación de ubicación pendiente.',
                    ], 422);
                }

                $statusPorAprobar = Status::where('description', '=', 'Por aprobar cambio de ubicacion')->first();
                if (!$statusPorAprobar) {
                    // Fallback si por alguna razón no existe el status
                    return response()->json(['status' => false, 'message' => 'Error: Status de aprobación no encontrado'], 500);
                }
                
                // Cambiar estado a "Por aprobar cambio de ubicacion"
                $order->status_id = $statusPorAprobar->id;
                $order->save();

                // Crear Review
                \App\Models\OrderLocationReview::create([
                    'order_id' => $order->id,
                    'user_id' => Auth::id(),
                    'status' => 'pending',
                ]);

                return response()->json([
                    'status' => true,
                    'message' => 'Solicitud enviada. Un gerente debe aprobar el cambio de ubicación.',
                    'order' => $order->load(['status', 'locationReviews']),
                ]);
            }
        }

        // 3. 🛑 INTERCEPCIÓN PARA APROBACIÓN DE RECHAZO 🛑
        $statusRechazado = Status::where('description', '=', 'Rechazado')->first();
        if ($statusRechazado && (int) $statusRechazado->id === (int) $request->status_id) {
            $userRole = Auth::user()->role?->description;
            if (!in_array($userRole, ['Gerente', 'Admin', 'Agencia'])) {
                
                // Verificar si ya existe una pendiente
                $existingPending = $order->rejectionReviews()->where('status', '=', 'pending')->first();
                if ($existingPending) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Ya existe una solicitud de rechazo pendiente.',
                    ], 422);
                }

                $statusPorAprobar = Status::where('description', '=', 'Por aprobar rechazo')->first();
                if (!$statusPorAprobar) {
                    return response()->json(['status' => false, 'message' => 'Error: Status de aprobación no encontrado'], 500);
                }
                
                // Cambiar estado a "Por aprobar rechazo"
                $order->status_id = $statusPorAprobar->id;
                $order->save();

                // Crear Review
                \App\Models\OrderRejectionReview::create([
                    'order_id' => $order->id,
                    'user_id' => Auth::id(),
                    'status' => 'pending',
                ]);

                return response()->json([
                    'status' => true,
                    'message' => 'Solicitud enviada. Un gerente debe aprobar el rechazo.',
                    'order' => $order->load(['status', 'rejectionReviews']),
                ]);
            }
        }

        // 4. 🛑 INTERCEPCIÓN PARA ASIGNAR A AGENCIA (AUTO) 🛑
        $statusAsignarAgencia = Status::where('description', '=', 'Asignar a agencia')->first();
        if ($statusAsignarAgencia && (int) $statusAsignarAgencia->id === (int) $request->status_id) {
            // Si ya tiene agencia, no hacemos nada extra, solo seguimos
            if (!$order->agency_id) {
                $clientCityName = $order->client?->city;
                $cityMatch = null;

                if ($clientCityName) {
                    // Buscar ciudad por nombre (insensible a mayúsculas/minúsculas)
                    $cityMatch = \App\Models\City::whereRaw('UPPER(name) = ?', [strtoupper(trim($clientCityName))])->first();
                }

                if ($cityMatch && $cityMatch->agency_id) {
                    $order->city_id = $cityMatch->id;
                    $order->agency_id = $cityMatch->agency_id;
                    $order->delivery_cost = $cityMatch->delivery_cost_usd;
                    // El status se guardará más abajo
                } else {
                    // Si no se pudo auto-asignar, devolvemos error para que se elija manualmente
                    return response()->json([
                        'status' => false,
                        'message' => 'No se pudo auto-asignar una agencia para la ciudad "' . ($clientCityName ?? 'Sin Ciudad') . '". Por favor, asígnela manualmente.',
                        'require_manual_agency' => true
                    ], 422);
                }
            }
        }

        $oldStatusId = $order->status_id;

        $order->status_id = $request->status_id;

        // Novedades
        if ($request->filled('novedad_type')) {
            $order->novedad_type = $request->novedad_type;
            $order->novedad_description = $request->novedad_description;
        }

        if ($request->filled('novedad_resolution')) {
            $order->novedad_resolution = $request->novedad_resolution;
        }

        $statusEnRuta = Status::where('description', '=', 'En ruta')->first();
        if ($statusEnRuta && (int)$statusEnRuta->id === (int)$order->status_id) {
            $order->was_shipped = true;
            $order->shipped_at = now();

            // Asignación automática de agencia por ciudad
            if ($order->city_id) {
                $city = \App\Models\City::find($order->city_id);
                if ($city && $city->agency_id) {
                    $order->agency_id = $city->agency_id;
                    $order->delivery_cost = $city->delivery_cost_usd;
                }
            }
        }

        $order->save();

        $oldStatusId = $order->getOriginal('status_id');

        // Descontar inventario solo si cambia a Entregado
        if ($statusEntregado && (int) $statusEntregado->id === (int) $order->status_id) {
            // Solo generamos ganancias cuando CAMBIA a Entregado
            if ($oldStatusId !== $order->status_id) {
                $commissionService->generateForDeliveredOrder($order);
            }

            foreach ($order->products as $op) {
                // Buscar inventario en la bodega de la agencia (si tiene) o principal
                $warehouseId = $order->agency?->warehouse?->id ?? \App\Models\Warehouse::where('is_main', '=', true)->first()?->id;
                if ($warehouseId) {
                    $inv = \App\Models\Inventory::where('product_id', '=', $op->product_id)
                        ->where('warehouse_id', '=', $warehouseId)
                        ->first();

                    if ($inv) {
                        $inv->decrement('quantity', $op->quantity);
                    }
                }
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Estado actualizado',
            'order' => $order->fresh(['status', 'updates.user', 'payments', 'products.product', 'cancellations.user', 'deliveryReviews', 'locationReviews', 'rejectionReviews', 'client', 'agent', 'agency', 'deliverer'])
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Error al actualizar estado: ' . $e->getMessage()
        ], 500);
    }
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
        $cityName = $orderData['customer']['default_address']['city'] ?? null;
        $cityId = null;
        if ($cityName) {
            $cityMatch = \App\Models\City::whereRaw('UPPER(name) = ?', [strtoupper(trim($cityName))])->first();
            if ($cityMatch) {
                $cityId = $cityMatch->id;
            }
        }

        $order = Order::updateOrCreate(
            ['order_id' => $orderData['id']],
            [
                'name'                => $orderData['name'],
                'current_total_price' => $orderData['current_total_price'],
                'order_number'        => $orderData['order_number'],
                'processed_at'        => $orderData['processed_at'] ?? null,
                'currency'            => $orderData['currency'],
                'client_id'           => $client->id,
                'city_id'             => $cityId,
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
        $upsellTotal = $order->products()->where('is_upsell', '=', true)->get(['*'])->sum(fn($p) => $p->price * $p->quantity);
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

        $query = Order::with(['client', 'agent', 'deliverer', 'status'])->latest('updated_at');

        // 🔒 Reglas por rol
        $role = $user->role?->description; // "Vendedor", "Gerente", "Admin", etc.

        if ($role === 'Vendedor') {
            // Un Vendedor SOLO ve lo que tiene asignado. No ve el "Backlog" de órdenes nuevas sin asignar.
            $query->where('agent_id', $user->id);
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
        } elseif ($role === 'Agencia') {
            $query->where('agency_id', $user->id);
        } else {
            // Gerente/Admin → filtros opcionales
            if ($request->filled('agent_id')) {
                $query->where('agent_id', $request->agent_id);
            }
            if ($request->filled('agency_id')) {
                $query->where('agency_id', '=', $request->agency_id);
            }
            if ($request->filled('city_id')) {
                $query->where('city_id', '=', $request->city_id);
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

        // Buscar el status "Asignado a vendedor"
        $statusId = Status::where('description', '=', 'Asignado a vendedor')->first()?->id;

        $order->status_id = $statusId;
        $order->agent_id = $agent->id;
        $order->save();

        return response()->json([
            'status' => true,
            'order' => $order->load('agent', 'status', 'client'),
        ]);
    }
    public function assignAgency(Request $request, Order $order)
    {
        $request->validate([
            'agency_id' => 'required|exists:users,id',
        ]);

        $agency = User::findOrFail($request->agency_id);

        if ($agency->role->description !== 'Agencia') {
            return response()->json([
                'status' => false,
                'message' => 'El usuario seleccionado no es una agencia válida'
            ], 422);
        }

        // Buscar el status "Asignar a agencia"
        $statusId = Status::where('description', '=', 'Asignar a agencia')->first()?->id;

        $order->status_id = $statusId;
        $order->agency_id = $agency->id;
        $order->save();

        return response()->json([
            'status' => true,
            'order' => $order->load('agency', 'status', 'client'),
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
        $item = OrderProduct::where('order_id', '=', $order->id)->where('id', '=', $itemId)->firstOrFail();

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
    public function uploadPaymentReceipt(Request $request, Order $order)
    {
        $request->validate([
            'payment_receipt' => 'required|image|max:10240', // 10MB
        ]);

        if ($request->hasFile('payment_receipt')) {
            // Eliminar anterior si existe
            if ($order->payment_receipt) {
                if (Storage::disk('public')->exists($order->payment_receipt)) {
                    Storage::disk('public')->delete($order->payment_receipt);
                }
            }

            $path = $request->file('payment_receipt')->store('payment_receipts', 'public');
            
            $order->payment_receipt = $path;
            $order->save();

            // URL para el preview inmediato en frontend
            $url = url("api/orders/{$order->id}/payment-receipt");

            return response()->json([
                'status' => true,
                'message' => 'Comprobante subido exitosamente',
                'payment_receipt_url' => $url,
                'order' => $order
            ]);
        }

        return response()->json(['status' => false, 'message' => 'No se recibió ninguna imagen'], 400);
    }

    public function getPaymentReceipt(Order $order)
    {
        if (!$order->payment_receipt) {
            abort(404, 'No hay comprobante');
        }

        // Asegurarse de la ruta correcta en storage/app/public
        $path = storage_path('app/public/' . $order->payment_receipt);

        if (!file_exists($path)) {
            abort(404, 'Archivo no encontrado');
        }

        return response()->file($path);
    }

    public function setReminder(Request $request, Order $order)
    {
        $request->validate([
            'reminder_at' => 'required|date',
        ]);

        $order->reminder_at = $request->reminder_at;
        $order->save();

        return response()->json([
            'status' => true,
            'message' => 'Recordatorio guardado',
            'order' => $order,
        ]);
    }

    public function updateChange(Request $request, Order $order)
    {
        try {
            $userRole = Auth::user()->role?->description;
            if (!in_array($userRole, ['Gerente', 'Admin', 'Vendedor'])) {
                return response()->json(['status' => false, 'message' => 'No tiene permisos para editar el vuelto'], 403);
            }

            $validated = $request->validate([
                'cash_received' => 'nullable|numeric',
                'change_amount' => 'nullable|numeric',
                'change_covered_by' => 'nullable|in:agency,company,partial',
                'change_amount_company' => 'nullable|numeric',
                'change_amount_agency' => 'nullable|numeric',
                'change_method_company' => 'nullable|string',
                'change_method_agency' => 'nullable|string',
                'change_rate' => 'nullable|numeric',
            ]);

            // Validación extra si es parcial
            if ($request->change_covered_by === 'partial') {
                $total = (float) ($request->change_amount_company ?? 0) + (float) ($request->change_amount_agency ?? 0);
                if (abs($total - (float) $request->change_amount) > 0.01) {
                    return response()->json([
                        'status' => false, 
                        'message' => 'La suma de los montos (Empresa + Agencia) debe ser igual al total del vuelto'
                    ], 422);
                }
            }

            $order->fill($validated);
            $order->save();

            return response()->json([
                'status' => true,
                'message' => 'Vuelto actualizado correctamente',
                'order' => $order->fresh(['status', 'agency', 'deliverer', 'agent', 'client'])
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
    public function updateLogistics(Request $request, Order $order)
    {
        $userRole = Auth::user()->role?->description;
        if (!in_array($userRole, ['Gerente', 'Admin'])) {
            return response()->json(['status' => false, 'message' => 'No tiene permisos para editar la logística'], 403);
        }

        $validated = $request->validate([
            'city_id' => 'nullable|exists:cities,id',
            'agency_id' => 'nullable|exists:users,id',
            'deliverer_id' => 'nullable|exists:users,id',
        ]);

        $order->fill($validated);
        $order->save();

        return response()->json([
            'status' => true,
            'message' => 'Logística actualizada correctamente',
            'order' => $order->fresh(['status', 'agency', 'deliverer', 'city', 'agent', 'client'])
        ]);
    }
    public function autoAssignAllLogistics(Request $request)
    {
        $userRole = Auth::user()->role?->description;
        if (!in_array($userRole, ['Gerente', 'Admin'])) {
            return response()->json(['status' => false, 'message' => 'No autorizado'], 403);
        }

        // 1. Buscar todas las órdenes sin agencia
        $orders = Order::whereNull('agency_id')->with('client')->get();
        $assignedCount = 0;

        // 2. Mapeo de ciudades y sus agencias asignadas
        $cities = City::whereNotNull('agency_id')->get()->keyBy('id');

        foreach ($orders as $order) {
            $cityId = $order->city_id ?? $order->client?->city_id;
            
            if ($cityId && isset($cities[$cityId])) {
                // Asignar el agency_id configurado en la ciudad
                $order->agency_id = $cities[$cityId]->agency_id;
                $order->save();
                $assignedCount++;
            }
        }

        return response()->json([
            'status' => true,
            'message' => "Se han auto-asignado {$assignedCount} órdenes exitosamente.",
            'total_pending' => Order::whereNull('agency_id')->count()
        ]);
    }
}
