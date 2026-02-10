<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\CompanyAccountController;
use App\Http\Controllers\CityController;
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


// Login
Route::post('login', [AuthController::class, 'login']);
Route::post('forgot-password', [\App\Http\Controllers\ForgotPasswordController::class, 'sendResetLinkEmail']);
Route::post('reset-password', [\App\Http\Controllers\ForgotPasswordController::class, 'reset']);

Route::post('order/webhook/{shop_id?}', [ShopifyWebhookController::class, 'handleOrderCreate']);

// Public route for payment receipts (no auth required to view images)
Route::get('/orders/{order}/payment-receipt', [OrderController::class, 'getPaymentReceipt']);
Route::get('/orders/{order}/change-receipt', [OrderController::class, 'getChangeReceipt']);
Route::post('test/register', [AuthController::class, 'testRegister']);

// Endpoints
Route::middleware('auth:sanctum')->group(function () {
    /**---------------------
     * USERS
     * ---------------------**/
    // Listar usuarios
    Route::get('users', [AuthController::class, 'get_all_users']);
    // Crear usuario
    Route::post('users', [AuthController::class, 'store']);
    // Editar datos del usuario
    Route::put('user/{user}', [AuthController::class, 'edit_user_data']);
    // Validar token y obtener datos del usuario logueado
    Route::get('user/data', [AuthController::class, 'get_logged_user_data']);
    
    // Dashboard data
    // Dashboard data
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index']);

    // Statuses
    Route::get('/statuses', [StatusController::class, 'index']);

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
    Route::get('users/role/{role}', [AuthController::class, 'usersByRole']);
    // Asignar agente a la orden
    Route::put('orders/{order}/assign-agent', [OrderController::class, 'assignAgent']);
    Route::put('orders/{order}/assign-agency', [OrderController::class, 'assignAgency']);
    
    // Configuración de Flujo de Ordenes (Reglas de Status)
    Route::get('config/flow', function (\Illuminate\Http\Request $request) {
        $role = $request->user()->role?->description;
        
        // Intentar match con mayúscula, minúscula o exacto
        $flow = config("order_flow.{$role}") 
             ?? config("order_flow." . ucfirst($role)) 
             ?? config("order_flow." . strtolower($role));

        if (!$flow) {
            return response()->json(['transitions' => null, 'debug_role_received' => $role]);
        }

        return response()->json($flow);
    });

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
    // Listar roles
    Route::get('roles', [RoleController::class, 'index']);
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
    Route::put('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::get('/orders/{order}/available-statuses', [OrderController::class, 'getAvailableStatuses']);
    Route::put('/orders/{order}/logistics', [OrderController::class, 'updateLogistics']);
    // Auto-asignación masiva de logística
    Route::post('/orders/auto-assign-logistics', [OrderController::class, 'autoAssignAllLogistics']);
    Route::get('/orders/pending-vueltos', [OrderController::class, 'getPendingVueltos']);
    Route::get('/orders/lite/counts', [OrderController::class, 'liteCounts']); // Lite Dashboard Counts
    // Ver detalles de la orden
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    // Agregar nota a la orden
    Route::post('/orders/{order}/updates', [OrderUpdateController::class, 'store']);
    // Solicitar cancelación
    Route::post('orders/{order}/cancel', [OrderCancellationController::class, 'store']);
    // Obtener productos de la orden
    Route::get('orders/{id}/products', [OrderController::class, 'getOrderProducts']);
    // Listar ordenes
    Route::get('orders', [OrderController::class, 'index']);
    // Crear orden manualmente
    Route::post('orders', [OrderController::class, 'store']);
    // Historial de actividades (Audit log)
    Route::get('orders/{order}/activities', [OrderController::class, 'getActivityLogs']);
    // Upselling
    Route::post('orders/{order}/upsell', [OrderController::class, 'addUpsell']);
    Route::delete('orders/{order}/upsell/{itemId}', [OrderController::class, 'removeUpsell']);
    // Productos
    Route::get('products', [ProductController::class, 'index']);

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
    // Auto-assign Cities
    Route::post('orders/auto-assign-cities', [App\Http\Controllers\OrderController::class, 'autoAssignCities']);

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
    Route::put('/orders/{order}/change', [OrderController::class, 'updateChange']);
    Route::get('/order/{order}/products', [OrderController::class, 'getOrderProducts']);
    Route::put('/orders/{order}/location', [OrderController::class, 'addLocation']);
    Route::apiResource('banks', \App\Http\Controllers\BankController::class);

    Route::post('/orders/{order}/payment-receipt', [OrderController::class, 'uploadPaymentReceipt']);
    Route::post('/orders/{order}/change-receipt', [OrderController::class, 'uploadChangeReceipt']);
    Route::put('/orders/{order}/reminder', [OrderController::class, 'setReminder']);
    Route::put('/orders/{order}/toggle-notification', [OrderController::class, 'toggleChangeNotification']);

    Route::get('/currency', [CurrencyController::class, 'show']);
    Route::post('/currency', [CurrencyController::class, 'create']);

    /**---------------------
     * DELIVERY REVIEW
     * ---------------------**/
    Route::put('/orders/delivery-review/{review}/approve', [\App\Http\Controllers\OrderDeliveryReviewController::class, 'approve']);
    Route::put('/orders/delivery-review/{review}/reject', [\App\Http\Controllers\OrderDeliveryReviewController::class, 'reject']);

    /**---------------------
     * LOCATION REVIEW
     * ---------------------**/
    Route::put('/orders/location-review/{review}/approve', [\App\Http\Controllers\OrderLocationReviewController::class, 'approve']);
    Route::put('/orders/location-review/{review}/reject', [\App\Http\Controllers\OrderLocationReviewController::class, 'reject']);

    /**---------------------
     * REJECTION REVIEW
     * ---------------------**/
    Route::put('/orders/rejection-review/{review}/approve', [\App\Http\Controllers\OrderRejectionReviewController::class, 'approve']);
    Route::put('/orders/rejection-review/{review}/reject', [\App\Http\Controllers\OrderRejectionReviewController::class, 'reject']);

    Route::post('/orders/{order}/postpone', [OrderPostponementController::class, 'store']);
    Route::post('/orders/{order}/create-return', [OrderController::class, 'createReturn']);

    /**---------------------
     * WAREHOUSES
     * ---------------------**/
    // Warehouse management
    Route::get('warehouse-types', [WarehouseController::class, 'getTypes']);
    Route::apiResource('warehouses', WarehouseController::class);
    Route::apiResource('banks', BankController::class);
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

    /**---------------------
     * SHOPS
     * ---------------------**/
    Route::get('shops', [\App\Http\Controllers\ShopController::class, 'index']);
    Route::post('shops', [\App\Http\Controllers\ShopController::class, 'store']);
    Route::put('shops/{shop}', [\App\Http\Controllers\ShopController::class, 'update']);
    Route::delete('shops/{shop}', [\App\Http\Controllers\ShopController::class, 'destroy']);
    Route::post('shops/{shop}/assign-sellers', [\App\Http\Controllers\ShopController::class, 'assignSellers']);

    /**---------------------
     * METRICS
     * ---------------------**/
    Route::get('metrics', [\App\Http\Controllers\MetricsController::class, 'index']);
    Route::get('business-metrics', [\App\Http\Controllers\BusinessMetricsController::class, 'index']);
    Route::post('metrics/ad-spend', [\App\Http\Controllers\MetricsController::class, 'storeAdSpend']);

    /**---------------------
     * CITIES
     * ---------------------**/
    Route::apiResource('cities', CityController::class);

    /**---------------------
     * PROVINCES
     * ---------------------**/
    Route::apiResource('provinces', \App\Http\Controllers\ProvinceController::class);

    /**---------------------
     * COMPANY ACCOUNTS
     * ---------------------**/
    Route::apiResource('company-accounts', CompanyAccountController::class);

    // TEST NOTIFICATIONS
    Route::post('/test/notifications', [\App\Http\Controllers\TestNotificationController::class, 'trigger']);
});
