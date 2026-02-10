<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Client;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\Status;
use App\Models\User;
use App\Models\City;
use App\Models\PaymentReceipt;
use App\Services\CommissionService;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\InventoryMovement;
use App\Models\Warehouse;
use App\Notifications\OrderAssignedNotification;
use App\Notifications\OrderNoveltyNotification;
use App\Notifications\OrderNoveltyResolvedNotification;
use App\Notifications\OrderScheduledNotification;

class OrderController extends Controller
{
    public function products(Order $order) {}
    public function updatePayment(Request $request, Order $order)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        $order->load(['status']); 
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

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
                'rate'   => (in_array($paymentData['method'], ['BOLIVARES_EFECTIVO', 'PAGOMOVIL', 'TRANSFERENCIA_BANCARIA_BOLIVARES'])) 
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
        $order->cash_received = $totalPaid;
        $order->change_amount = max(0, $totalPaid - $order->current_total_price);
        $order->save();

        return response()->json(['status' => true, 'order' => $order->fresh('payments')]);
    }

    public function autoAssignCities()
    {
        $orders = Order::whereNull('city_id')->with('client')->get();
        $count = 0;

        foreach ($orders as $order) {
            /** @var \App\Models\Order $order */
            $cityName = $order->client->city ?? $order->client->province;
            if ($cityName) {
                // Busqueda flexible
                $cityMatch = City::where('name', 'LIKE', trim($cityName))->first();
                if ($cityMatch) {
                    $order->city_id = $cityMatch->id;
                    $order->save();
                    $count++;
                }
            }
        }

        return response()->json([
            'status' => true,
            'message' => "Se han asignado ciudades a $count órdenes.",
            'updated_count' => $count
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
            'paymentReceipts', // 👈 Payment Receipts Gallery
            'agency', // 👈 incluimos agencia
            'postponements.user', // 👈 incluimos historial de reprogramación
            'shop', // 👈 incluimos tienda
            'returnOrders', // 👈 devoluciones creadas desde esta orden
            'parentOrder', // 👈 orden padre si es devolución
        ])->findOrFail($id);

    // 🔒 RESTRICCIÓN AGENCIA: No deben ver órdenes canceladas
    $user = \Illuminate\Support\Facades\Auth::user();
    if ($user->role?->description === 'Agencia') {
        if ($order->status?->description === 'Cancelado') {
            return response()->json(['status' => false, 'message' => 'No tienes permiso para ver esta orden.'], 403);
        }
    }

        // 📦 CHECK STOCK AVAILABILITY
        $stockCheck = $order->getStockDetails();
        $hasStockWarning = $stockCheck['has_warning'];

        // Si quieres devolver items “planchados” (recomendado para front):
        $items = $order->products->map(function ($op) use ($stockCheck) {
            $productStock = $stockCheck['items'][$op->product_id] ?? ['available' => 0, 'has_stock' => false];
            return [
                'id'        => $op->id,
                'product_id' => $op->product_id,
                'showable_name' => $op->showable_name ?? ($op->product->showable_name ?? null),
                'title'     => $op->title ?? ($op->product->title ?? $op->product->name ?? 'Producto'),
                'sku'       => $op->product->sku ?? null,
                'image'     => $op->image ?? $op->product->image ?? null,
                'price'     => (float) $op->price,
                'quantity'  => (int) $op->quantity,
                'subtotal'  => (float) $op->price * (int) $op->quantity,
                'is_upsell' => (bool) $op->is_upsell,
                'upsell_user_name' => $op->upsellUser?->names ?? null,
                'stock_available' => $productStock['available'],
                'has_stock' => $productStock['has_stock'],
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
                'agent'                => (\Illuminate\Support\Facades\Auth::user()->role?->description === 'Agencia') ? null : $order->agent,
                'status'               => $order->status,
                'products'             => $items,
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
                'eur_rate'             => \App\Models\Setting::where('key', '=', 'rate_bcv_eur')->first()?->value ?? 0,
                'agency'               => $order->agency,
                'has_stock_warning'    => $hasStockWarning, // 👈 New flag
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
                'change_payment_details' => $order->change_payment_details,
                'change_receipt'        => $order->change_receipt,
                'postponements'         => $order->postponements,
                'is_return'             => $order->is_return,
                'is_exchange'           => $order->is_exchange,
                'parent_order_id'       => $order->parent_order_id,
                'parent_order'          => $order->parentOrder,
                'return_orders'         => $order->returnOrders,
                'receipts_gallery'      => $order->paymentReceipts->toArray(),
            ]
        ]);
    }
        public function updateStatus(Request $request, Order $order, CommissionService $commissionService)
    {
        try {
            // Permitir busqueda por nombre ('status') o ID ('status_id')
            $request->validate([
                'status'    => 'required_without:status_id|string|exists:statuses,description',
                'status_id' => 'required_without:status|integer|exists:statuses,id',
            ]);

            // 🔒 LOCK: Si ya está Entregado, nadie (salvo Admin) puede cambiar el status
            if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
                 return response()->json(['status' => false, 'message' => 'La orden ya fue entregada. Solo un Admin puede modificarla.'], 403);
            }

            // Resolver el objeto Status
            if ($request->has('status')) {
                $targetStatus = Status::where('description', $request->status)->firstOrFail();
            } else {
                $targetStatus = Status::findOrFail($request->status_id);
            }

            $newStatusId = $targetStatus->id;
            $newStatusRaw = $targetStatus->description;

            // 🛡️ RESTRICCIÓN DE FLUJO POR ROL (Rule Enforcement)
            $userRole = Auth::user()->role?->description;
            // Roles exentos de validación de flujo
            $superRoles = ['Admin', 'Gerente', 'Master'];

            if (!in_array($userRole, $superRoles)) {
                $transitions = config("order_flow.{$userRole}.transitions");

                if ($transitions) {
                    $currentStatusRaw = $order->status?->description ?? 'Nuevo';
                    
                    if ($currentStatusRaw && $newStatusRaw) {
                        $allowedNext = $transitions[$currentStatusRaw] ?? [];

                        if (!in_array($newStatusRaw, $allowedNext)) {
                            return response()->json([
                                'status' => false,
                                'message' => "⛔ ACCIÓN NO PERMITIDA: Como {$userRole}, no puedes pasar de '{$currentStatusRaw}' a '{$newStatusRaw}'. Sigue el flujo establecido."
                            ], 422);
                        }
                    }
                }
            }

            // Actualizar request con el ID resuelto para compatibilidad con código legacy abajo
            $request->merge(['status_id' => $newStatusId]);

            // Buscamos status "Entregado" y "En ruta" para validación de stock
            $statusEntregado = Status::where('description', '=', 'Entregado')->first();
            $statusEnRuta = Status::where('description', '=', 'En ruta')->first();
            $statusCambioUbicacion = Status::where('description', '=', 'Cambio de ubicacion')->first();

            // 🛑 VALIDACIÓN DE STOCK ANTES DE PASAR A "Entregado" o "En ruta"
            // Return/exchange orders also require stock validation since they deduct from inventory
            if (($statusEntregado && (int) $statusEntregado->id === (int) $request->status_id) || 
                ($statusEnRuta && (int) $statusEnRuta->id === (int) $request->status_id)) {
                
                $isAdmin = Auth::user()->role?->description === 'Admin';
                
                // Si el status ya era "Entregado" o "En ruta", permitimos el cambio (ya fue validado o permitido antes)
                $wasAlreadyTransitOrDelivered = ($statusEntregado && (int)$order->status_id === (int)$statusEntregado->id) ||
                                               ($statusEnRuta && (int)$order->status_id === (int)$statusEnRuta->id);

                if (!$isAdmin && !$wasAlreadyTransitOrDelivered && !$order->hasStock()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'No hay suficiente stock en el almacén de la agencia para procesar esta orden.',
                    ], 422);
                }
            }

        // 1. Validar que existe comprobante de pago si se intenta cambiar a Entregado
        // SKIP for return/exchange orders - they don't require payments
        if ($statusEntregado && (int) $statusEntregado->id === (int) $request->status_id && !($order->is_return || $order->is_exchange)) {
            if (empty($order->payment_receipt)) {
                return response()->json([
                    'status' => false,
                    'message' => 'No se puede marcar como entregado sin un comprobante de pago',
                ], 422);
            }

            // Validar efectivo/vueltos solo si el método de pago es EFECTIVO
            $cashMethods = ['DOLARES_EFECTIVO', 'BOLIVARES_EFECTIVO', 'EUROS_EFECTIVO'];
            if ($order->payment_method === 'EFECTIVO' || $order->payments()->whereIn('method', $cashMethods)->exists()) {
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
        // ... (existing logic for Delivered)
        }

        // 1.5. 🛑 VALIDACIÓN PARA NOVEDAD SOLUCIONADA 🛑
        // Debe tener ubicación y pagos completos antes de marcarse como solucionada
        $statusNovedadSolucionada = Status::where('description', '=', 'Novedad Solucionada')->first();
        if ($statusNovedadSolucionada && (int) $statusNovedadSolucionada->id === (int) $request->status_id) {
            
            // a) Validar Ubicación
            if (empty($order->location)) {
                 return response()->json([
                    'status' => false,
                    'message' => 'Se requiere una ubicación (coordenadas) para marcar como solucionada 📍',
                ], 422);
            }

            // b) Validar Pagos (Si no es retorno/cambio)
            if (!$order->is_return && !$order->is_exchange) {
                // Verificar existencia de pagos
                if ($order->payments()->count() === 0) {
                     return response()->json([
                        'status' => false,
                        'message' => 'Se requieren métodos de pago registrados para solucionar la novedad 💳',
                    ], 422);
                }

                // Verificar monto total cubierto
                $totalPaid = $order->payments()->sum('amount');
                // Use a small epsilon for float comparison
                if ($totalPaid < ($order->current_total_price - 0.01)) {
                     return response()->json([
                        'status' => false,
                        'message' => "El monto pagado ($" . number_format($totalPaid, 2) . ") es menor al total ($" . number_format($order->current_total_price, 2) . "). Debe cubrirse el total.",
                    ], 422);
                }

                // Verificar vuelto/excedente
                if ($totalPaid > ($order->current_total_price + 0.01)) {
                    if (empty($order->change_covered_by)) {
                        return response()->json([
                            'status' => false,
                            'message' => "El monto pagado excede el total. Debe registrar quién cubre el vuelto (Agencia/Empresa) 💸",
                        ], 422);
                    }
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

                // Crear Review (Observer will handle the activity log)
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
                } else {
                    // 🛑 RECHAZAR CAMBIO DE ESTADO
                    // Si no tiene ubicación válida, NO DEBE pasar a "Asignar a Agencia"
                    if (!$order->city_id && !$request->city_id && !$request->agency_id) {
                         return response()->json([
                            'status' => false,
                            'message' => 'No se puede enviar a Agencia sin una ubicación. La ciudad "' . ($clientCityName ?? 'Sin Ciudad') . '" no tiene agencia automática. Selecciona una manualmente.',
                            'require_manual_agency' => true
                        ], 422);
                    }
                }
            }
        }

        $oldStatusId = $order->status_id;

        $order->status_id = $request->status_id;

        // NOTA: Ya NO desasignamos cuando se programa para otro día
        // La vendedora mantiene la orden hasta que se cierre la tienda
        // La desasignación ocurre en el comando de cierre de tienda

        // 🔔 NOTIFICACIONES Y TIMER
        $statusNovedad = Status::where('description', '=', 'Novedades')->first();
        $statusNovedadSoluciodada = Status::where('description', '=', 'Novedad Solucionada')->first();
        $statusProgramadoMasTarde = Status::where('description', '=', 'Programado para mas tarde')->first();
        $statusAsignarAgencia = Status::where('description', '=', 'Asignar a agencia')->first();

        // Si cambia a Novedades
        if ($statusNovedad && (int)$statusNovedad->id === (int)$request->status_id && (int)$oldStatusId !== (int)$statusNovedad->id) {
            // Notificar a Admins/Gerentes
            $admins = User::whereHas('role', function($q){ $q->whereIn('description', ['Admin', 'Gerente']); })->get();
            foreach ($admins as $admin) {
                $admin->notify(new OrderNoveltyNotification($order, "Nueva novedad reportada en orden #{$order->name}"));
            }
            
            // 🔔 NEW: Notificar también a la vendedora asignada
            if ($order->agent) {
                $order->agent->notify(new OrderNoveltyNotification($order, "Atención: Se ha reportado una novedad en tu orden #{$order->name}"));
            }
        }

        // Si cambia a Novedad Solucionada
        if ($statusNovedadSoluciodada && (int)$statusNovedadSoluciodada->id === (int)$request->status_id && (int)$oldStatusId !== (int)$statusNovedadSoluciodada->id) {
            if ($order->agent) {
                $order->agent->notify(new OrderNoveltyResolvedNotification($order, "Novedad solucionada en orden #{$order->name}"));
            }
            // Notify Agency as well (Prevent double notification if logged user is the agency)
            if ($order->agency && $order->agency_id !== Auth::id()) {
                $order->agency->notify(new OrderNoveltyResolvedNotification($order, "Novedad solucionada en orden #{$order->name}"));
            } elseif ($order->agency_id) {
                $agencyUser = User::find($order->agency_id);
                if ($agencyUser) {
                    $agencyUser->notify(new OrderNoveltyResolvedNotification($order, "Novedad solucionada en orden #{$order->name}"));
                }
            }
        }

        // Si cambia a Programado para más tarde
        if ($statusProgramadoMasTarde && (int)$statusProgramadoMasTarde->id === (int)$request->status_id && (int)$oldStatusId !== (int)$statusProgramadoMasTarde->id) {
            // Notificar a Admins/Gerentes
            $admins = User::whereHas('role', function($q){ $q->whereIn('description', ['Admin', 'Gerente']); })->get();
            foreach ($admins as $admin) {
                $admin->notify(new OrderScheduledNotification($order, "Orden #{$order->name} programada para más tarde"));
            }
        }

        // ⏱️ TIMER: Si entra en "Asignar a agencia" o "En ruta", marcamos el inicio del cronómetro de 45 min
        if (($statusAsignarAgencia && (int)$statusAsignarAgencia->id === (int)$request->status_id) || 
            ($statusEnRuta && (int)$statusEnRuta->id === (int)$request->status_id)) {
            if (!$order->received_at) {
                $order->received_at = now();
            }
        }

        // Novedades
        if ($request->filled('novedad_type')) {
            $order->novedad_type = $request->novedad_type;
            $order->novedad_description = $request->novedad_description;
        }

        if ($request->filled('novedad_resolution')) {
            $order->novedad_resolution = $request->novedad_resolution;
        }

        $statusEnRuta = Status::where('description', '=', 'En ruta')->first();
        if ($statusEnRuta && (int)$statusEnRuta->id === (int)$order->status_id && (int)$oldStatusId !== (int)$statusEnRuta->id) {
            
            // 🛑 VALIDACIÓN AGENCIA: Solo pasar a En Ruta desde Solucionada si hubo cambio de ubicación
            $statusNovedadSolucionada = Status::where('description', '=', 'Novedad Solucionada')->first();
            if ($statusNovedadSolucionada && (int)$oldStatusId === (int)$statusNovedadSolucionada->id) {
                // Verificar tipo de novedad
                // Normalizamos el string por si acaso vienen con mayúsculas/tildes distintas
                if (stripos($order->novedad_type, 'bicaci') === false) { // Busca 'ubicaci' de Ubicación
                     return response()->json([
                        'status' => false,
                        'message' => '⛔ SOLO se puede volver a poner "En Ruta" si la novedad fue por "Cambio de ubicación".',
                    ], 422);
                }
            }

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


            // 🚀 Generar gasto de agencia cuando pasa por "En ruta" (solo la primera vez)
            if ($order->agency_id) {
                $agencyUser = \App\Models\User::find($order->agency_id);
                if ($agencyUser) {
                    \App\Models\Earning::firstOrCreate([
                        'order_id'     => $order->id,
                        'user_id'      => $agencyUser->id,
                        'role_type'    => 'agencia',
                    ], [
                        'amount_usd'   => $agencyUser->delivery_cost > 0 ? $agencyUser->delivery_cost : ($order->delivery_cost ?? 0),
                        'currency'     => 'USD',
                        'rate'         => 1,
                        'earning_date' => now()->toDateString(),
                    ]);
                }
            }
        }

        $order->save();

        // 🔔 NOTIFICAR AGENCIA SI HUBO CAMBIOS RELEVANTES
        // Notificamos si:
        // 1. El estado ahora es "Asignar a agencia" o "En ruta"
        // 2. Y (Cambió el status O Cambió la agencia asignada)
        if ($order->agency_id && $order->wasChanged(['status_id', 'agency_id'])) {
            $isAgencyStatus = ($statusAsignarAgencia && (int)$order->status_id === (int)$statusAsignarAgencia->id) ||
                              ($statusEnRuta && (int)$order->status_id === (int)$statusEnRuta->id);
            
            if ($isAgencyStatus) {
                 $agency = User::find($order->agency_id);
                 if ($agency) {
                     try {
                         // Evitar auto-notificación si la misma agencia está haciendo la acción (ej. mover a En ruta)
                         if (\Illuminate\Support\Facades\Auth::id() !== $agency->id) {
                             $agency->notify(new OrderAssignedNotification($order, "Nueva orden asignada a tu agencia: #{$order->name}"));
                         }
                     } catch (\Exception $e) {
                         \Log::error('Error sending agency notification: ' . $e->getMessage());
                     }
                 }
            }
       }

        // Descontar inventario solo si cambia a Entregado
        if ($statusEntregado && (int) $statusEntregado->id === (int) $order->status_id) {
            // Solo descontamos y generamos ganancias cuando CAMBIA a Entregado
            if ($oldStatusId !== $order->status_id) {
                $order->processed_at = now();
                $order->save();
                
                // 🔄 Skip commissions for return/exchange orders - they don't generate revenue
                if (!$order->is_return && !$order->is_exchange) {
                    $commissionService->generateForDeliveredOrder($order);
                }

                foreach ($order->products as $op) {
                    $deducted = false;

                    // 1. Intentar descontar del STOCK DEL REPARTIDOR (si tiene)
                    if ($order->deliverer_id) {
                        $todayStock = \App\Models\DelivererStock::where('deliverer_id', $order->deliverer_id)
                            ->where('date', now()->toDateString())
                            ->first();
                        
                        if ($todayStock) {
                            $item = $todayStock->items()->where('product_id', $op->product_id)->first();
                            if ($item) {
                                $item->increment('qty_delivered', $op->quantity);
                                $deducted = true;
                            }
                        }
                    }

                    // 2. Si no se descontó del repartidor (porque no maneja stock diario o no tiene item),
                    // descontamos de la BODEGA DE LA AGENCIA (o principal).
                    if (!$deducted) {
                        // Buscar inventario en la bodega de la agencia (si tiene) o principal
                        $warehouseId = $order->agency?->warehouse?->id ?? Warehouse::where('is_main', '=', true)->first()?->id;
                        if ($warehouseId) {
                            $inv = \App\Models\Inventory::where('product_id', '=', $op->product_id)
                                ->where('warehouse_id', '=', $warehouseId)
                                ->first();

                            if ($inv) {
                                $inv->decrement('quantity', $op->quantity);
                                
                                // 📝 REGISTRAR MOVIMIENTO EN EL HISTORIAL
                                // Use different note for return/exchange orders
                                $movementNote = ($order->is_return || $order->is_exchange) 
                                    ? "Devolución/Cambio entregado - Orden #{$order->name}" 
                                    : "Venta efectuada - Orden #{$order->name}";
                                    
                                InventoryMovement::create([
                                    'product_id' => $op->product_id,
                                    'from_warehouse_id' => $warehouseId,
                                    'to_warehouse_id' => null,
                                    'quantity' => $op->quantity,
                                    'movement_type' => 'out',
                                    'reference_type' => 'Order',
                                    'reference_id' => $order->id,
                                    'user_id' => Auth::id() ?? 1,
                                    'notes' => $movementNote,
                                ]);
                            }
                        }
                    }
                }
            }
        }

        // 📡 BROADCAST EVENT: OrderUpdated
        event(new \App\Events\OrderUpdated($order));

        return response()->json([
            'status' => true,
            'message' => 'Estado actualizado',
            'order' => $order->fresh(['status', 'client', 'agent', 'agency', 'deliverer'])
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
                    'showable_name' => $op->showable_name ?? ($op->product->showable_name ?? null),
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
                // Guardamos province en el campo city para reutilizar la infraestructura existente
                'city'            => $orderData['customer']['default_address']['province'] ?? null,
                'address1'        => $orderData['customer']['default_address']['address1'] ?? null,
                'address2'        => $orderData['customer']['default_address']['address2'] ?? null,
            ]
        );

        // 2️⃣ Guardar/actualizar orden
        // Buscar "ciudad" (que ahora es provincia) en la tabla cities
        $provinceName = $orderData['customer']['default_address']['province'] ?? null;
        $cityId = null;
        if ($provinceName) {
            $cityMatch = \App\Models\City::whereRaw('UPPER(name) = ?', [strtoupper(trim($provinceName))])->first();
            if ($cityMatch) {
                $cityId = $cityMatch->id;
            }
        }

        $order = Order::updateOrCreate(
            ['order_id' => $orderData['id']],
            [
                'name'                => $orderData['name'],
                'current_total_price' => round($orderData['current_total_price']),
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
                    'is_upsell'  => false,
                ],
                [
                    'product_number' => $product->product_id,
                    'title'          => $item['title'],
                    'name'           => $item['name'] ?? null,
                    'price'          => round($item['price']),
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
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

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
                $oldLocation = $new_order->location;
                
                // Solo actualizar si viene un valor real, para evitar borrados accidentales
                if (!empty($request->location)) {
                    $new_order->location = $request->location;
                    $new_order->save();
                }

                // Manual log if observer missed it or for double safety
                if ($oldLocation !== $location_url) {
                    \App\Models\OrderActivityLog::create([
                        'order_id' => $new_order->id,
                        'user_id' => auth()->id(),
                        'action' => 'updated',
                        'description' => "Actualizó 'Ubicación' de '{$oldLocation}' a '{$location_url}'",
                        'properties' => ['location' => ['old' => $oldLocation, 'new' => $location_url]]
                    ]);
                }
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

        $query = Order::with(['client', 'agent', 'deliverer', 'status', 'payments', 'shop', 'agency'])
            ->withCount('updates')
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc');

        // 🔒 Reglas por rol (Case insensitive & trimmed)
        $roleName = $user->role ? strtolower(trim($user->role->description)) : '';

        if ($roleName === 'vendedor') {
            // Un Vendedor SOLO ve lo que tiene asignado
            $query->where('agent_id', $user->id);
            
            // Restricción: Si es Entregado, solo mostrar de hoy
            $query->where(function($q) {
                // Mostrar si NO es Entregado
                $q->whereDoesntHave('status', function($sq) {
                    $sq->where('description', 'Entregado');
                })
                // O si ES Entregado, que sea de hoy
                ->orWhere(function($q2) {
                    $q2->whereHas('status', function($sq) {
                        $sq->where('description', 'Entregado');
                    })->whereDate('updated_at', now());
                });
            });
        } elseif ($roleName === 'repartidor') {
            $query->where('deliverer_id', $user->id)
                  ->whereDate('updated_at', now());
        } elseif ($roleName === 'agencia') {
            $query->where('agency_id', $user->id)
                  ->whereDoesntHave('status', function($sq) {
                      $sq->where('description', 'Cancelado');
                  });
        }
        
        // Filtros Generales (Aplican a todos si los parámetros están presentes)
        // Nota: Admin/Gerente puede filtrar por agent_id, agency_id, etc.
        // Los roles restringidos ya tienen sus restricciones aplicadas arriba.
        // Si un Vendedor intenta ?agent_id=OTRO, la restricción de arriba (agent_id=YO) + la de abajo (agent_id=OTRO) devolverá vacio. Correcto.
        
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
            $query->whereDate('updated_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('updated_at', '<=', $request->date_to);
        }
        if ($request->filled('status')) {
             $statusDesc = $request->status;
             if ($statusDesc === 'Reprogramado para hoy') {
                 $query->whereDate('scheduled_for', now()->toDateString())
                       ->whereHas('status', function($q) {
                           $q->whereNotIn('description', ['Cancelado', 'Entregado']);
                       });
             } else {
                 $query->whereHas('status', function($q) use ($statusDesc) {
                     $q->where('description', $statusDesc);
                 });
             }
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function($q) use ($term) {
                $q->where('id', 'like', "%{$term}%")
                  ->orWhere('name', 'like', "%{$term}%") // Shopify order name
                  ->orWhereHas('client', function($cq) use ($term) {
                      $cq->where('first_name', 'like', "%{$term}%")
                         ->orWhere('last_name', 'like', "%{$term}%")
                         ->orWhere('phone', 'like', "%{$term}%");
                  });
            });
        }

        $orders = $query->paginate($perPage);

        $binanceRate = \App\Models\Setting::where('key', '=', 'rate_binance_usd')->first()?->value ?? 0;
        $bcvRate = \App\Models\Setting::where('key', '=', 'rate_bcv_usd')->first()?->value ?? 0;

        $mappedData = collect($orders->items())->map(function ($order) use ($binanceRate, $bcvRate) {
            // Check and sync status ONLY if it's not already in terminal status
            $order->syncStockStatus();
            
            $check = $order->getStockDetails();
            $orderArray = $order->toArray();

            // 🔒 HIDE AGENT FOR AGENCIES
            if (\Illuminate\Support\Facades\Auth::user()->role?->description === 'Agencia') {
                $orderArray['agent'] = null;
                $orderArray['agent_id'] = null;
            }

            $orderArray['has_stock_warning'] = $check['has_warning'];
            $orderArray['binance_rate'] = $binanceRate;
            $orderArray['bcv_rate'] = $bcvRate;
            
            // Reload status in case it changed
            if ($orderArray['status_id'] !== $order->getOriginal('status_id')) {
                $orderArray['status'] = $order->status ? $order->status->toArray() : null;
            }
            
            return $orderArray;
        });

        return response()->json([
            'status' => true,
            'data'   => $mappedData,
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

        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

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

        // 📡 Broadcast for real-time updates
        event(new \App\Events\OrderUpdated($order));

        // 🔔 Notify Agent
        try {
            $agent->notify(new OrderAssignedNotification($order, "Nueva orden asignada: #{$order->name}"));
        } catch (\Exception $e) {
            // Log error but don't fail the request
            \Log::error('Error sending notification: ' . $e->getMessage());
        }

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

        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

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
        
        // ⏱️ TIMER: Iniciar cronómetro si no existe
        if (!$order->received_at) {
            $order->received_at = now();
        }

        $order->save();

        // 📦 Immediately sync stock status after agency assignment
        $order->syncStockStatus();

        // 🔔 Notify Agency
        try {
            $agency->notify(new OrderAssignedNotification($order, "Nueva orden asignada a tu agencia: #{$order->name}"));
        } catch (\Exception $e) {
            \Log::error('Error sending notification: ' . $e->getMessage());
        }

        // 📡 BROADCAST EVENT: Ensure frontend updates for agency
        event(new \App\Events\OrderUpdated($order));

        return response()->json([
            'status' => true,
            'order' => $order->load('agency', 'status', 'client'),
        ]);
    }
    public function addUpsell(Request $request, Order $order)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

        // For return orders, price validation is optional (always 0)
        $rules = [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ];
        
        // Only require price for regular orders (not returns/exchanges)
        if (!($order->is_return || $order->is_exchange)) {
            $rules['price'] = 'required|numeric|min:0';
        }
        
        $request->validate($rules);

        $product = Product::findOrFail($request->product_id);
        
        // For return/exchange orders, price is always 0 and is_upsell is false
        $isReturnOrExchange = ($order->is_return || $order->is_exchange);
        
        // Determine if it is an upsell
        // Default: YES (unless it's a return/exchange)
        // If 'is_upsell' is explicitly FALSE in request AND user is Admin/Gerente -> NO
        $userRole = auth()->user()->role?->description;
        $isAdminOrManager = in_array($userRole, ['Admin', 'Gerente', 'Master']);
        $requestedRegular = $request->has('is_upsell') && !$request->boolean('is_upsell');
        
        $isUpsell = !$isReturnOrExchange; // Default true for normal orders
        if ($isReturnOrExchange) {
            $isUpsell = false;
        } elseif ($isAdminOrManager && $requestedRegular) {
            $isUpsell = false;
        }

        $productPrice = $isReturnOrExchange ? 0 : $request->price;

        OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_number' => $product->product_id,
            'title' => $product->title,
            'name' => $product->name,
            'showable_name' => $product->showable_name,
            'price' => $productPrice,
            'quantity' => $request->quantity,
            'image' => $product->image,
            'is_upsell' => $isUpsell,
            'upsell_user_id' => $isUpsell ? auth()->id() : null,
        ]);

        // Only update total for non-return/exchange orders
        if (!$isReturnOrExchange) {
            $upsellAmount = $request->price * $request->quantity;
            $order->current_total_price += $upsellAmount;
            $order->save();

            // 🆕 Si la orden ya está ENTREGADA, sincronizamos las comisiones de inmediato
            if ($order->status && $order->status->description === 'Entregado') {
                app(CommissionService::class)->generateForDeliveredOrder($order);
            }
        }

        return response()->json([
            'status' => true,
            'message' => $isReturnOrExchange ? 'Producto agregado a la devolución/cambio' : 'Upsell agregado correctamente',
            'order' => $order->load('products.product', 'client', 'status', 'agent')
        ]);
    }

    public function removeUpsell(Order $order, $itemId)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

        $item = OrderProduct::where('order_id', '=', $order->id)->where('id', '=', $itemId)->firstOrFail();

        // For return/exchange orders, allow deleting any product (not just upsells)
        // For regular orders, ONLY ADMIN can delete original products
        $isAdmin = \Illuminate\Support\Facades\Auth::user()->role?->description === 'Admin';
        
        if (!$isAdmin && !($order->is_return || $order->is_exchange) && !$item->is_upsell) {
            return response()->json(['status' => false, 'message' => 'No es un upsell. Solo admins pueden eliminar productos base.'], 403);
        }

        $deduction = $item->price * $item->quantity;
        $item->delete();

        // Only update total for non-return/exchange orders (they always have $0 total)
        if (!($order->is_return || $order->is_exchange)) {
            $order->current_total_price -= $deduction;
            if ($order->current_total_price < 0) $order->current_total_price = 0; // Safety check
            $order->save();

            // 🆕 Si la orden ya está ENTREGADA, sincronizamos las comisiones (quitará el upsell/producto del reporte)
            if ($order->status && $order->status->description === 'Entregado') {
                app(CommissionService::class)->generateForDeliveredOrder($order);
            }
        }

        return response()->json([
            'status' => true,
            'message' => ($order->is_return || $order->is_exchange) ? 'Producto eliminado de la devolución/cambio' : 'Producto eliminado correctamente',
            'order' => $order->load('products.product', 'client', 'status', 'agent')
        ]);
    }

    public function updateProductQuantity(Request $request, Order $order, $itemId)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $item = OrderProduct::where('order_id', $order->id)->where('id', $itemId)->firstOrFail();

        // For return/exchange orders, price is 0 so impact is just quantity
        // For regular orders, we recalculate total
        
        // Calculate difference for total price update
        $oldQuantity = $item->quantity;
        $newQuantity = $request->input('quantity');
        
        if ($oldQuantity == $newQuantity) {
             return response()->json([
                'status' => true,
                'message' => 'Cantidad actualizada correctamente',
                'order' => $order->fresh(['products.product', 'client', 'status', 'agent'])
            ]);
        }

        $price = $item->price;
        $diff = ($newQuantity - $oldQuantity) * $price;

        // Update item
        $item->quantity = $newQuantity;
        $item->save();

        // Update order total
        if (!($order->is_return || $order->is_exchange)) {
            $order->current_total_price += $diff;
            if ($order->current_total_price < 0) $order->current_total_price = 0;
            $order->save();
            
            // 🆕 Si la orden ya está ENTREGADA, sincronizamos las comisiones
            if ($order->status && $order->status->description === 'Entregado') {
                app(CommissionService::class)->generateForDeliveredOrder($order);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Cantidad actualizada correctamente',
            'order' => $order->fresh(['products.product', 'client', 'status', 'agent'])
        ]);
    }

    public function create() {}
    public function store(Request $request)
    {
        // 1. Validation
        $request->validate([
            'client_name' => 'required|string',
            'client_phone' => 'required|string',
            'client_province' => 'required|string',
            'client_address' => 'nullable|string',
            'products' => 'required|array|min:1',
            'products.*.id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.price' => 'nullable|numeric|min:0',
            'agent_id' => 'nullable|exists:users,id'
        ]);

        \DB::beginTransaction();
        try {
            // 2. Client Logic
            // Clean phone number / logic could be added here, but for now direct usage
            // 2. Client Logic
            // Clean phone number / logic check
            $client = Client::where('phone', $request->client_phone)->first();

            if (!$client) {
                // Use a large random number ID for manual clients to mock Shopify ID
                $tempId = (int) (microtime(true) * 1000); 
                
                $client = Client::create([
                    'phone'           => $request->client_phone,
                    'customer_id'     => $tempId,
                    'customer_number' => $tempId,
                    'first_name'      => $request->client_name,
                    'province'        => $request->client_province,
                    'city'            => $request->client_province, 
                    'address1'        => $request->client_address,
                    'country_name'    => 'Venezuela'
                ]);
            } else {
                // Update basic info to match latest manual entry
                $client->update([
                    'first_name' => $request->client_name,
                    'province'   => $request->client_province,
                    'city'       => $request->client_province, 
                    'address1'   => $request->client_address,
                ]);
            }

            // 3. Determine Agent
            $currentUser = Auth::user();
            $agentId = null;

            if ($currentUser->role->description === 'Vendedor') {
                $agentId = $currentUser->id;
            } else {
                // Admin/Gerente logic
                if ($request->has('agent_id')) {
                    $agentId = $request->agent_id;
                } else {
                    $agentId = null; 
                }
            }

            // 4. City/Agency Logic
            $cityMatch = null;
            if ($request->client_province) {
                 $cityMatch = \App\Models\City::whereRaw('UPPER(name) = ?', [strtoupper(trim($request->client_province))])->first();
            }
            
            $cityId = $cityMatch ? $cityMatch->id : null;
            $agencyId = $cityMatch ? $cityMatch->agency_id : null;
            $deliveryCost = $cityMatch ? $cityMatch->delivery_cost_usd : 0;

            // 5. Create Order Header
            $lastOrder = Order::orderBy('id', 'desc')->first();
            $nextId = $lastOrder ? $lastOrder->id + 1 : 1;
            $orderName = "MAN-" . $nextId;

            $statusDescription = $agentId ? 'Asignado a vendedor' : 'Nuevo';
            $status = Status::where('description', '=', $statusDescription)->first();

            // Generate a manual order_id (mocking Shopify ID)
            $manualOrderId = (int) (microtime(true) * 1000);

            $order = Order::create([
                'order_id' => $manualOrderId, // Add generated ID
                'name' => $orderName,
                'order_number' => $nextId, 
                'currency' => 'USD', 
                'current_total_price' => 0, 
                'client_id' => $client->id,
                'agent_id' => $agentId,
                'agency_id' => $agencyId,
                'city_id' => $cityId,
                'delivery_cost' => $deliveryCost,
                'status_id' => $status ? $status->id : 1,
            ]);

            // 6. Products
            $total = 0;
            foreach ($request->products as $p) {
                $product = Product::find($p['id']);
                $quantity = $p['quantity'];
                
                $price = isset($p['price']) ? $p['price'] : $product->price;

                OrderProduct::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_number' => $product->product_id, // Shopify ID mapping
                    'name' => $product->name,
                'title' => $product->title,
                'showable_name' => $product->showable_name,
                'sku' => $product->sku,
                    'price' => $price, 
                    'quantity' => $quantity,
                    'image' => $product->image
                ]);

                $total += ($price * $quantity);
            }

            $order->current_total_price = $total;
            $order->save();

            // Log activity
            \App\Models\OrderActivityLog::create([
                'order_id' => $order->id,
                'user_id' => $currentUser->id,
                'action' => 'created',
                'description' => "Orden creada manualmente por " . ($currentUser->name ?? 'Usuario')
            ]);
            
            // Notify Agent if assigned and user is not the agent
            if ($agentId && $agentId !== $currentUser->id) {
                 $agent = User::find($agentId);
                 if ($agent) {
                     try {
                         $agent->notify(new OrderAssignedNotification($order, "Nueva orden manual asignada: #{$order->name}"));
                     } catch (\Exception $e) {}
                 }
            }

            // 🔔 NOTIFY ADMINS 🔔
            try {
                $admins = User::whereHas('role', function($q) {
                    $q->where('description', 'Admin');
                })->get();
                
                foreach ($admins as $admin) {
                     // Don't notify if the admin created it themselves (optional preference, but usually good to notify other admins)
                     if ($admin->id !== $currentUser->id) { 
                        $admin->notify(new OrderAssignedNotification($order, "Nueva orden manual creada: #{$order->name} por {$currentUser->names}"));
                     }
                }
            } catch (\Exception $e) {}

            \DB::commit();

            return response()->json([
                'status' => true, 
                'order' => $order->fresh(['client', 'status', 'products', 'agent']),
                'message' => 'Orden creada exitosamente'
            ]);

        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
    public function edit(Order $order) {}
    
    /**
     * Get available status transitions for a specific order
     * Validates: payments, location, change, stock, and role-based flow rules
     */
    public function getAvailableStatuses(Request $request, Order $order)
    {
        $user = Auth::user();
        $userRole = $user->role?->description;
        
        // Get all statuses
        $allStatuses = Status::all();
        
        // Get flow rules for this role
        $superRoles = ['Admin', 'Gerente', 'Master'];
        $transitions = in_array($userRole, $superRoles) 
            ? null 
            : config("order_flow.{$userRole}.transitions");
        
        $currentStatus = $order->status?->description ?? 'Nuevo';
        $allowedByFlow = $transitions ? ($transitions[$currentStatus] ?? []) : $allStatuses->pluck('description')->toArray();
        
        // Business validations
        $totalPaid = $order->payments->sum('amount');
        $currentTotal = $order->current_total_price ?? 0;
        $changeAmount = $totalPaid - $currentTotal;
        
        $hasPayments = $totalPaid > 0;
        $hasChangeInfo = (abs($changeAmount) < 0.01) || ($changeAmount > 0 && !empty($order->change_covered_by));
        $hasLocation = !empty($order->location) && trim($order->location) !== '';
        
        // Public statuses for sellers (don't require payment validation)
        $sellerPublicStatuses = [
            'Llamado 1', 'Llamado 2', 'Llamado 3',
            'Programado para otro dia', 'Programado para mas tarde',
            'Cancelado', 'Novedad Solucionada', 'Esperando Ubicacion', 'Confirmado'
        ];
        
        // Filter statuses based on all validations
        $availableStatuses = $allStatuses->filter(function($status) use (
            $order, $userRole, $currentStatus, $allowedByFlow, $hasPayments, $hasChangeInfo, $hasLocation, $sellerPublicStatuses
        ) {
            $statusName = $status->description;
            
            // Always include current status (for UI check mark)
            if ($statusName === $currentStatus) {
                return true;
            }
            
            // Check flow rules first
            if (!in_array($statusName, $allowedByFlow)) {
                return false;
            }
            
            // Skip all business validations for return/exchange orders
            if ($order->is_return || $order->is_exchange) {
                return true;
            }
            
            // 🔒 Validation for "Asignar a agencia"
            if ($statusName === 'Asignar a agencia') {
                return $hasPayments && $hasChangeInfo && $hasLocation;
            }
            
            // 🔒 Stock validation for "Entregado" and "En ruta"
            if (in_array($statusName, ['Entregado', 'En ruta'])) {
                if ($order->has_stock_warning && $userRole !== 'Admin') {
                    return false;
                }
            }
            
            // 🔒 Payment receipt validation for "Entregado" - REMOVED to allow option to show
            // Validation remains in updateStatus() to ensure integrity
            /*id ($statusName === 'Entregado' && empty($order->payment_receipt)) {
                return false;
            }*/
            
            // 🔒 General seller validation for non-public statuses
            if ($userRole === 'Vendedor' && !in_array($statusName, $sellerPublicStatuses)) {
                return $hasPayments && $hasChangeInfo;
            }
            
            // 🔒 Special rule for "Novedad Solucionada" -> "En ruta"
            // Solo permitir volver a ruta si la novedad fue por cambio de ubicación
            if ($currentStatus === 'Novedad Solucionada' && $statusName === 'En ruta') {
                 if (stripos($order->novedad_type, 'bicaci') === false) { 
                      return false;
                 }
            }
            
            return true;
        });
        
        return response()->json([
            'statuses' => $availableStatuses->values(),
            'current_status' => $currentStatus,
            'validations' => [
                'has_payments' => $hasPayments,
                'has_change_info' => $hasChangeInfo,
                'has_location' => $hasLocation,
            ]
        ]);
    }
    
    public function destroy(Order $order) {}
    public function uploadPaymentReceipt(Request $request, Order $order)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        $order->load(['status']);
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

        $request->validate([
            'payment_receipt' => 'nullable|image|max:10240', // 10MB
            'payment_receipts' => 'nullable|array',
            'payment_receipts.*' => 'image|max:10240',
        ]);

        $files = [];
        if ($request->hasFile('payment_receipt')) {
            $files[] = $request->file('payment_receipt');
        }
        if ($request->hasFile('payment_receipts')) {
            foreach($request->file('payment_receipts') as $file) {
                $files[] = $file;
            }
        }

        if (empty($files)) {
             return response()->json(['status' => false, 'message' => 'No se recibió ninguna imagen'], 400);
        }

        foreach ($files as $file) {
            $path = $file->store('payment_receipts', 'public');
            $originalName = $file->getClientOriginalName();
            
            $order->paymentReceipts()->create([
                'path' => $path,
                'original_name' => $originalName
            ]);
            
            // Backward compatibility (store the latest one)
            $order->payment_receipt = $path;
            $order->save();
        }

        // URL para el preview inmediato en frontend (de la última)
    $url = url("api/orders/{$order->id}/payment-receipt");

    $freshOrder = $order->fresh(['paymentReceipts', 'status', 'client', 'agent', 'agency', 'payments', 'shop']);
    $orderArray = $freshOrder->toArray();
    $orderArray['receipts_gallery'] = $freshOrder->paymentReceipts->toArray();

    return response()->json([
        'status' => true,
        'message' => 'Comprobante(s) subido(s) exitosamente',
        'payment_receipt_url' => $url,
        'order' => $orderArray
    ]);
    }

    public function deletePaymentReceipt(Order $order, $receiptId)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        $order->load(['status']);
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

        $receipt = $order->paymentReceipts()->where('id', $receiptId)->first();
        if (!$receipt) {
            return response()->json(['status' => false, 'message' => 'Recibo no encontrado'], 404);
        }

        // Eliminar del storage
        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($receipt->path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($receipt->path);
        }

        // Eliminar de BD
        $receipt->delete();

        // Actualizar el campo payment_receipt si era el que se borró y quedan más
        $remaining = $order->paymentReceipts()->latest()->first();
        if ($remaining) {
            $order->payment_receipt = $remaining->path;
        } else {
            $order->payment_receipt = null;
        }
        $order->save();

        $freshOrder = $order->fresh(['paymentReceipts', 'status', 'client', 'agent', 'agency', 'payments', 'shop']);
        $orderArray = $freshOrder->toArray();
        $orderArray['receipts_gallery'] = $freshOrder->paymentReceipts->toArray();

        return response()->json([
            'status' => true,
            'message' => 'Comprobante eliminado exitosamente',
            'order' => $orderArray
        ]);
    }

    public function getPaymentReceipt(Request $request, Order $order)
    {
        if (!$order->payment_receipt) {
            abort(404, 'No hay comprobante');
        }

        // Asegurarse de la ruta correcta en storage/app/public
        $path = storage_path('app/public/' . $order->payment_receipt);

        if (!file_exists($path)) {
            abort(404, 'Archivo no encontrado');
        }

        if ($request->has('download')) {
            $filename = "Pago_Orden_{$order->name}." . pathinfo($path, PATHINFO_EXTENSION);
            return response()->download($path, $filename);
        }

        return response()->file($path);
    }

    public function getReceipt(Request $request, PaymentReceipt $receipt)
    {
        $path = storage_path('app/public/' . $receipt->path);

        if (!file_exists($path)) {
            abort(404, 'Archivo no encontrado');
        }
        
        if ($request->has('download')) {
             $filename = "Recibo_" . $receipt->id . "." . pathinfo($path, PATHINFO_EXTENSION);
             return response()->download($path, $filename);
        }

        return response()->file($path);
    }

    public function uploadChangeReceipt(Request $request, Order $order)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        $order->load(['status']);
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

        $request->validate([
            'change_receipt' => 'required|image|max:10240', // 10MB
        ]);

        if ($request->hasFile('change_receipt')) {
            // Eliminar anterior si existe
            $extra = $order->changeExtra()->firstOrCreate(['order_id' => $order->id]);
            
            // Eliminar anterior si existe
            if ($extra->change_receipt) {
                if (Storage::disk('public')->exists($extra->change_receipt)) {
                    Storage::disk('public')->delete($extra->change_receipt);
                }
            }

            $path = $request->file('change_receipt')->store('change_receipts', 'public');
            
            $extra->change_receipt = $path;
            $extra->save();

            // Log activity
            \App\Models\OrderActivityLog::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'action' => 'updated',
                'description' => "Subió el comprobante del vuelto (pago móvil).",
            ]);

            // Add update note
            \App\Models\OrderUpdate::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'message' => "✅ Comprobante de vuelto subido por administración."
            ]);

            // Notify Agent (Seller)
            if ($order->agent) {
                $order->agent->notify(new \App\Notifications\ChangeReceiptUploaded($order));
            }

            // URL para el preview inmediato
            $url = url("api/orders/{$order->id}/change-receipt");

            return response()->json([
                'status' => true,
                'message' => 'Comprobante de vuelto subido exitosamente',
                'change_receipt_url' => $url,
                'order' => $order
            ]);
        }

        return response()->json(['status' => false, 'message' => 'No se recibió ninguna imagen'], 400);
    }

    public function getChangeReceipt(Request $request, Order $order)
    {
        if (!$order->change_receipt) {
            abort(404, 'No hay comprobante de vuelto');
        }

        $path = storage_path('app/public/' . $order->change_receipt);

        if (!file_exists($path)) {
            abort(404, 'Archivo no encontrado');
        }

        if ($request->has('download')) {
            $filename = "Vuelto_Orden_{$order->name}." . pathinfo($path, PATHINFO_EXTENSION);
            return response()->download($path, $filename);
        }

        return response()->file($path);
    }

    public function setReminder(Request $request, Order $order)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

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
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        $order->load(['status']);
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

        try {
            $userRole = Auth::user()->role?->description;
            if (!in_array($userRole, ['Gerente', 'Admin', 'Vendedor'])) {
                return response()->json(['status' => false, 'message' => 'No tiene permisos para editar el vuelto'], 403);
            }

            \DB::enableQueryLog();

            $input = $request->all();
            
            // Si el campo viene como string (desde FormData), intentamos decodificarlo
            if (isset($input['change_payment_details']) && is_string($input['change_payment_details'])) {
                $decoded = json_decode($input['change_payment_details'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $input['change_payment_details'] = $decoded;
                }
            }

            $numericFields = ['cash_received', 'change_amount', 'change_amount_company', 'change_amount_agency', 'change_rate'];
            foreach ($numericFields as $field) {
                if (isset($input[$field]) && $input[$field] === "") {
                    $input[$field] = 0;
                }
            }

            $validated = \Validator::make($input, [
                'cash_received' => 'nullable|numeric',
                'change_amount' => 'nullable|numeric',
                'change_covered_by' => 'nullable|in:agency,company,partial',
                'change_amount_company' => 'nullable|numeric',
                'change_amount_agency' => 'nullable|numeric',
                'change_method_company' => 'nullable|string',
                'change_method_agency' => 'nullable|string',
                'change_rate' => 'nullable|numeric',
                'change_payment_details' => 'nullable',
            ])->validate();

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

            // Sincronización explicita de montos por si acaso
            if ($order->change_covered_by === 'company') {
                $order->change_amount_company = $order->change_amount;
                $order->change_amount_agency = 0;
            } elseif ($order->change_covered_by === 'agency') {
                $order->change_amount_agency = $order->change_amount;
                $order->change_amount_company = 0;
            }

            $order->save();

            // Save extra details (workaround for ALTER privileges)
            $extra = $order->changeExtra()->firstOrCreate(['order_id' => $order->id]);
            $details = $input['change_payment_details'] ?? null;
            if (is_string($details)) {
                $details = json_decode($details, true);
            }
            
            \Log::info("Saving extra details for Order {$order->id}", ['details_input' => $details]);

            $extra->change_payment_details = $details;
            $extra->save();

            $refreshedOrder = $order->fresh(['status', 'agency', 'deliverer', 'agent', 'client', 'changeExtra']);
            
            \Log::info("Queries executed:", \DB::getQueryLog());
            
            \Log::info("Refreshed Order Data:", [
                'change_payment_details_attr' => $refreshedOrder->change_payment_details,
                'changeExtra_relation' => $refreshedOrder->changeExtra
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Vuelto actualizado correctamente',
                'order' => $refreshedOrder
            ]);
        } catch (\Exception $e) {
            \Log::error("UpdateChange Error: " . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function getPendingVueltos(Request $request)
    {
        $user = Auth::user();
        if (!$user->role || !in_array($user->role->description, ['Admin', 'Gerente'])) {
            return response()->json(['status' => false, 'message' => 'No autorizado'], 403);
        }

        $orders = Order::whereHas('status', function($q) {
                $q->where('description', 'Entregado');
            })
            ->whereIn('change_covered_by', ['company', 'partial'])
            ->where('change_amount_company', '>', 0)
            ->where(function($q) {
                // No tiene extra o tiene extra pero sin recibo
                $q->whereDoesntHave('changeExtra')
                  ->orWhereHas('changeExtra', function($sq) {
                      $sq->whereNull('change_receipt')
                         ->orWhere('change_receipt', '');
                  });
            })
            ->with(['client', 'agent', 'agency', 'changeExtra'])
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'orders' => $orders
        ]);
    }
    public function updateLogistics(Request $request, Order $order)
    {
        // 🔒 LOCK: No editar si está Entregado (excepto Admin)
        if ($order->status && $order->status->description === 'Entregado' && \Illuminate\Support\Facades\Auth::user()->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No se puede modificar una orden entregada.'], 403);
        }

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

        // 2. Mapeo de ciudades/provincias y sus agencias asignadas
        // Usando tabla cities que ahora contiene provincias
        $cities = \App\Models\City::whereNotNull('agency_id')->get()->keyBy('id');

        foreach ($orders as $order) {
            /** @var \App\Models\Order $order */
            // Intentar obtener city_id de la orden, o desde el cliente
            $cityId = $order->city_id;
            
            // Si no tiene city_id, intentar asignar desde el cliente usando province/city
            if (!$cityId && $order->client) {
                // Ahora client->city contiene la provincia
                $locationName = $order->client->city ?? $order->client->province;
                if ($locationName) {
                    $cityMatch = \App\Models\City::where('name', 'LIKE', trim($locationName))->first();
                    if ($cityMatch) {
                        $order->city_id = $cityMatch->id;
                        $cityId = $cityMatch->id;
                    }
                }
            }
            
            if ($cityId && isset($cities[$cityId])) {
                // Asignar el agency_id configurado en la ciudad/provincia
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
    public function getActivityLogs(Order $order)
    {
        // Solo Admin o Gerente pueden ver el historial completo de auditoría
        $userRole = auth()->user()->role?->description;
        if (!in_array($userRole, ['Admin', 'Gerente'])) {
            return response()->json([
                'status' => false,
                'message' => 'No tienes permiso para ver esta sección.'
            ], 403);
        }

        $logs = $order->activityLogs()
            ->with('user:id,names,surnames,email')
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'data' => $logs
        ]);
    }
    public function toggleChangeNotification(Order $order)
    {
        $user = Auth::user();
        // Allow Agent (Seller) or Admin/Gerente
        if ($user->id !== $order->agent_id && !in_array($user->role?->description, ['Admin', 'Gerente'])) {
            return response()->json(['status' => false, 'message' => 'No autorizado'], 403);
        }

        $extra = $order->changeExtra()->firstOrCreate(['order_id' => $order->id]);
        $details = $extra->change_payment_details ?? [];
        
        $currentState = $details['client_notified'] ?? false;
        $details['client_notified'] = !$currentState;
        
        $extra->change_payment_details = $details;
        $extra->save();

        return response()->json([
            'status' => true, 
            'message' => 'Estado de notificación actualizado',
            'client_notified' => $details['client_notified']
        ]);
    }

    /**
     * Retorna conteo de órdenes del día para el dashboard Lite (Vendedores)
     */
    public function liteCounts(Request $request)
    {
        try {
            $user = Auth::user();
            $query = Order::query();

            // 1. Filtro Usuario
            if ($user && $user->role && $user->role->description === 'Vendedor') {
                $query->where('orders.agent_id', $user->id);
            } else {
                $query->whereDate('orders.updated_at', now());
            }

            // 3. Agrupar y contar por descripcion del status
            $counts = $query->join('statuses', 'orders.status_id', '=', 'statuses.id')
                ->select(\DB::raw('statuses.description as status_name'), \DB::raw('count(*) as total'))
                ->groupBy('statuses.description')
                ->pluck('total', 'status_name');

            return response()->json([
                'status' => true,
                'counts' => $counts
            ]);
        } catch (\Exception $e) {
            \Log::error("Error in liteCounts: " . $e->getMessage());
            return response()->json(['status' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a return order based on an existing order.
     * Rules:
     * - is_return = true
     * - name appends "(DEVOLUCION)"
     * - current_total_price = 0 (client doesn't pay)
     * - agent_id = same as original
     * - status = "Asignado a agencia"
     * - agency_id = auto-assigned by city
     * - Products are cloned from original
     */
    public function createReturn(Request $request, Order $order)
    {
        $user = Auth::user();
        $type = $request->get('type', 'devolucion'); // 'devolucion' or 'cambio'

        // Only Admin, Gerente, or Vendedor can create returns/exchanges
        if (!in_array($user->role?->description, ['Admin', 'Gerente', 'Vendedor'])) {
            return response()->json(['status' => false, 'message' => 'No autorizado'], 403);
        }

        // Don't allow creating from a return/exchange
        if ($order->is_return || $order->is_exchange) {
            return response()->json(['status' => false, 'message' => 'No se puede crear una devolución/cambio de otra devolución/cambio'], 422);
        }

        // Only allow returns for delivered orders
        if ($order->status?->description !== 'Entregado') {
            return response()->json(['status' => false, 'message' => 'Solo se pueden crear devoluciones de órdenes entregadas'], 422);
        }

        // Find status "Asignar a agencia"
        $assignedStatus = Status::where('description', 'Asignar a agencia')->first();
        if (!$assignedStatus) {
            return response()->json(['status' => false, 'message' => 'Status "Asignar a agencia" no encontrado'], 500);
        }

        // Find agency by city
        $agencyId = null;
        if ($order->city_id) {
            $city = City::find($order->city_id);
            if ($city && $city->agency_id) {
                $agencyId = $city->agency_id;
            }
        }

        // Create return/exchange order
        // Generate unique numeric IDs (9 billion+ to distinguish from Shopify)
        $returnOrderId = 9000000000 + $order->id;
        $returnOrderNumber = 9000000000 + $order->id;
        
        $suffix = ($type === 'cambio') ? ' (CAMBIO)' : ' (DEVOLUCION)';
        
        $returnOrder = Order::create([
            'order_id' => $returnOrderId,
            'order_number' => $returnOrderNumber,
            'name' => $order->name . $suffix,
            'current_total_price' => 0, // Client doesn't pay
            'currency' => $order->currency,
            'processed_at' => now(),
            'client_id' => $order->client_id,
            'status_id' => $assignedStatus->id,
            'agent_id' => $order->agent_id,
            'city_id' => $order->city_id,
            'province_id' => $order->province_id,
            'agency_id' => $agencyId,
            'shop_id' => $order->shop_id,
            'location' => $order->location, // Copy delivery address/location link
            'is_return' => ($type === 'devolucion'),
            'is_exchange' => ($type === 'cambio'),
            'parent_order_id' => $order->id,
        ]);

        // Clone products from original order
        foreach ($order->products as $product) {
            OrderProduct::create([
                'order_id' => $returnOrder->id,
                'product_id' => $product->product_id,
                'title' => $product->title,
                'name' => $product->name,
                'showable_name' => $product->showable_name,
                'price' => 0, // No cost for return
                'quantity' => $product->quantity,
                'image' => $product->image,
                'is_upsell' => false,
            ]);
        }

        // Log activity
        $labelLabel = ($type === 'cambio') ? 'cambio' : 'devolución';
        \App\Models\OrderActivityLog::create([
            'order_id' => $returnOrder->id,
            'user_id' => $user->id,
            'action' => 'order_created',
            'description' => "Orden de {$labelLabel} creada desde orden #{$order->name}",
            'properties' => [
                'parent_order_id' => $order->id,
                'created_by' => $user->names ?? $user->email,
            ]
        ]);

        $successLabel = ($type === 'cambio') ? 'cambio' : 'devolución';
        return response()->json([
            'status' => true,
            'message' => "Orden de {$successLabel} creada exitosamente",
            'order' => $returnOrder->fresh(['client', 'status', 'products', 'agency']),
        ]);
    }
}
