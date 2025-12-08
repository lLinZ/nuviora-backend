<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DelivererStockController;
use App\Http\Controllers\EarningsController;
use App\Http\Controllers\FacebookEventController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\OrderCancellationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderDelivererController;
use App\Http\Controllers\OrderPostponementController;
use App\Http\Controllers\OrderUpdateController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

// Crear Admin
Route::post('register/master/24548539', [AuthController::class, 'register_master']);

// Login
Route::post('login', [AuthController::class, 'login']);

Route::post('order/webhook', [ShopifyWebhookController::class, 'handleOrderCreate']);

// Public route for payment receipts (no auth required to view images)
Route::get('/orders/{order}/payment-receipt', [OrderController::class, 'getPaymentReceipt']);

// Endpoints
Route::middleware('auth:sanctum')->group(function () {
    /**---------------------
     * USERS
     * ---------------------**/
    // Listar usuarios
    Route::get('users', [AuthController::class, 'get_all_users']);
    // Editar datos del usuario
    Route::put('user/{user}', [AuthController::class, 'edit_user_data']);
    // Validar token y obtener datos del usuario logueado
    Route::get('user/data', [AuthController::class, 'get_logged_user_data']);
    
    // Dashboard data
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index']);

    // Cambiar color
    Route::put('user/{user}/change/color', [AuthController::class, 'edit_color']);
    // Cambiar tema
    Route::put('user/{user}/change/theme', [AuthController::class, 'edit_theme']);
    // Cambiar password
    Route::put('user/{user}/change/password', [AuthController::class, 'edit_password']);
    // Registrar agente
    Route::post('register/agent', [AuthController::class, 'register_agent']);
    // Cerrar sesion
    Route::get('logout', [AuthController::class, 'logout']);
    // Listar agentes
    Route::get('users/agents', [AuthController::class, 'agents']);
    // Asignar agente a la orden
    Route::put('orders/{order}/assign-agent', [OrderController::class, 'assignAgent']);
    /**---------------------
     * STATUS
     * ---------------------**/
    // Crear status nuevo
    Route::post('status', [StatusController::class, 'create']);
    /**---------------------
     * ROLES
     * ---------------------**/
    // Crear rol nuevo
    Route::post('role', [RoleController::class, 'create']);
    Route::get('/users/deliverers', [AuthController::class, 'deliverers']);       // listar + buscar
    Route::post('/users/deliverers', [AuthController::class, 'storeDeliverer']);  // crear
    Route::put('/users/deliverers/{user}', [AuthController::class, 'updateDeliverer']); // editar
    Route::delete('/users/deliverers/{user}', [AuthController::class, 'destroyDeliverer']); // eliminar

    Route::get('/products', [ProductController::class, 'index']);

    Route::get('/users/deliverers', [AuthController::class, 'deliverers']);
    Route::post('/users/deliverers', [AuthController::class, 'storeDeliverer']); // crear repartidor
    Route::put('/orders/{order}/assign-deliverer', [OrderDelivererController::class, 'assign']);
    Route::get('/users/deliverers', [AuthController::class, 'deliverers']);       // listar + buscar
    Route::post('/users/deliverers', [AuthController::class, 'storeDeliverer']);  // crear
    Route::put('/users/deliverers/{user}', [AuthController::class, 'updateDeliverer']); // editar
    Route::delete('/users/deliverers/{user}', [AuthController::class, 'destroyDeliverer']); // eliminar

    /**---------------------
     * ORDERS
     * ---------------------**/
    // Actualizar estado de la orden    
    Route::put('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    // Ver detalles de la orden
    Route::get('/orders/{order}', [OrderUpdateController::class, 'show']);
    // Agregar nota a la orden
    Route::post('/orders/{order}/updates', [OrderUpdateController::class, 'store']);
    // Solicitar cancelación
    Route::post('orders/{order}/cancel', [OrderCancellationController::class, 'store']);
    // Obtener productos de la orden
    Route::get('orders/{id}/products', [OrderController::class, 'getOrderProducts']);
    // Listar ordenes
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('/orders/{order}/postpone', [OrderPostponementController::class, 'store']);
    /**---------------------
     * CURRENCY
     * ---------------------**/
    // Crear divisa
    // Route::post('currency', [CurrencyController::class, 'create']);
    // Obtener la ultima divisa
    // Route::get('currency', [CurrencyController::class, 'get_last_currency']);

    /*---------------------
     * CANCELACIONES DE ORDEN
     * ---------------------**/
    // Aprobar / Rechazar
    Route::put('cancellations/{cancellation}/review', [OrderCancellationController::class, 'review']);
    // Listar cancelaciones
    Route::get('cancellations', [OrderCancellationController::class, 'index']);

    // Productos 
    Route::get('/products', [ProductController::class, 'index']);

    // Stock
    Route::put('/products/{product}/stock', [ProductController::class, 'updateStock']);
    Route::get('/products/{product}/movements', [StockMovementController::class, 'index']);

    Route::post('facebook/events', [FacebookEventController::class, 'sendEvent']);
    Route::post('shopify/orders/create', [ShopifyWebhookController::class, 'orderCreated']);

    Route::get('/roster/today', [RosterController::class, 'today']); // GET roster actual
    Route::post('/roster/today', [RosterController::class, 'setToday']); // POST lista de agent_ids
    Route::post('/orders/assign-backlog', [AssignmentController::class, 'assignBacklog']);

    Route::get('/settings/business-hours', [SettingsController::class, 'getBusinessHours']);
    Route::put('/settings/business-hours', [SettingsController::class, 'updateBusinessHours']);
    Route::get('/business/today', [BusinessController::class, 'status']); // estado actual
    Route::get('/business/status', [BusinessController::class, 'status']); // estado actual
    Route::post('/business/open',   [BusinessController::class, 'open']);   // abrir jornada (ahora)
    Route::post('/business/close',  [BusinessController::class, 'close']);  // cerrar jornada (ahora)
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::put('/inventory/{product}/adjust', [InventoryController::class, 'adjust']); // IN/OUT

    Route::get('/deliverer/stock/today', [DelivererStockController::class, 'mineToday']); // para repartidor
    Route::post('/deliverer/stock/assign', [DelivererStockController::class, 'assign']);
    Route::post('/deliverer/stock/return', [DelivererStockController::class, 'return']);

    Route::get('/commissions/me/today', [CommissionController::class, 'myToday']);
    Route::get('/commissions/admin/summary', [CommissionController::class, 'adminSummary']); // ?from=YYYY-MM-DD&to=...
    // Productos
    Route::get('/inventory/products', [ProductController::class, 'index']);
    Route::post('/inventory/products', [ProductController::class, 'store']);
    Route::put('/inventory/products/{id}', [ProductController::class, 'update']);

    // Movimientos de stock
    Route::get('/stock/movements', [StockMovementController::class, 'index']); // opcional: filtro por fechas/sku
    Route::post('/stock/adjust', [StockMovementController::class, 'adjust']);  // IN/OUT
    Route::post('/stock/movements', [StockMovementController::class, 'store']);
    Route::get('/inventory', [InventoryController::class, 'index']);      // Admin / Gerente
    Route::get('/inventory/my', [InventoryController::class, 'myStock']); // Repartidor
    Route::get('/deliverer/stock/today', [DelivererStockController::class, 'mineToday']);
    Route::post('/deliverer/stock/open', [DelivererStockController::class, 'open']); // abre jornada y asigna items
    Route::post('/deliverer/stock/add-items', [DelivererStockController::class, 'addItems']); // agrega más durante el día
    Route::post('/deliverer/stock/deliver', [DelivererStockController::class, 'registerDeliver']); // marcar entregado por producto
    Route::post('/deliverer/stock/close', [DelivererStockController::class, 'close']); // devolver sobrante y cerrar

    // Gerente/Admin (ver por repartidor / fecha)
    Route::get('/deliverer/stock', [DelivererStockController::class, 'index']); // ?date=YYYY-MM-DD&deliverer_id=...

    Route::get('/deliverer/stock/own', [DelivererStockController::class, 'myStock']);            // resumen por producto
    Route::post('/deliverer/stock/assign', [DelivererStockController::class, 'assign']);     // “tomar” de bodega
    Route::post('/deliverer/stock/return', [DelivererStockController::class, 'return']);     // devolver a bodega
    Route::get('/deliverer/stock/movements', [DelivererStockController::class, 'myMovements']);

    Route::get('/earnings/summary', [EarningsController::class, 'summary']);
    Route::get('/earnings/me', [EarningsController::class, 'me']);

    // Ver stock de un repartidor (admin/gerente o el repartidor mismo)
    Route::get('/deliverers/{id}/stock', [DelivererStockController::class, 'show']);

    // Asignar stock (solo Admin/Gerente)
    Route::post('/deliverers/{id}/stock/assign', [DelivererStockController::class, 'assign']);

    // Registrar devolución
    Route::post('/deliverers/{id}/stock/return', [DelivererStockController::class, 'return']);

    Route::put('/orders/{order}/payment', [OrderController::class, 'updatePayment']);
    Route::get('/order/{order}/products', [OrderController::class, 'getOrderProducts']);
    Route::put('/orders/{order}/location', [OrderController::class, 'addLocation']);
    Route::post('/orders/{order}/payment-receipt', [OrderController::class, 'uploadPaymentReceipt']);

    Route::get('/currency', [CurrencyController::class, 'show']);
    Route::post('/currency', [CurrencyController::class, 'create']);

    /**---------------------
     * WAREHOUSES
     * ---------------------**/
    // Warehouse management
    Route::get('warehouse-types', [WarehouseController::class, 'getTypes']);
    Route::apiResource('warehouses', WarehouseController::class);
    Route::get('warehouses/{warehouse}/inventory', [WarehouseController::class, 'inventory']);

    /**---------------------
     * INVENTORY MOVEMENTS
     * ---------------------**/
    // Inventory movements
    Route::prefix('inventory-movements')->group(function () {
        Route::get('/', [InventoryMovementController::class, 'index']);
        Route::get('/{id}', [InventoryMovementController::class, 'show']);
        Route::post('/transfer', [InventoryMovementController::class, 'transfer']);
        Route::post('/in', [InventoryMovementController::class, 'stockIn']);
        Route::post('/out', [InventoryMovementController::class, 'stockOut']);
        Route::post('/adjust', [InventoryMovementController::class, 'adjust']);
    });
});
