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
use App\Http\Controllers\SalesGroupController;
use App\Http\Controllers\MyGroupController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\OrderTrackingComprehensiveController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Controllers\SupplyChainController;
use Illuminate\Support\Facades\Route;


// Login
Route::post('login', [AuthController::class, 'login']);
Route::post('forgot-password', [\App\Http\Controllers\ForgotPasswordController::class, 'sendResetLinkEmail']);
Route::post('reset-password', [\App\Http\Controllers\ForgotPasswordController::class, 'reset']);

Route::post('order/webhook/{shop_id?}', [ShopifyWebhookController::class, 'handleOrderCreate']);

// 🔒 Comprobantes: sin sesión (se abren como <img> o en otra pestaña), pero solo con un enlace firmado
// que genera el backend al devolver la orden (payment_receipt_url, change_receipt_url, receipts[].url).
Route::get('/orders/{order}/payment-receipt', [OrderController::class, 'getPaymentReceipt'])->name('receipts.order');
Route::get('/orders/receipt/{receipt}', [OrderController::class, 'getReceipt'])->name('receipts.show');
Route::get('/orders/{order}/change-receipt', [OrderController::class, 'getChangeReceipt'])->name('receipts.change');
// 🔒 Archivos del chat interno: igual, con el enlace firmado que entrega el chat (12 h)
Route::get('/internal-chat/attachments/{message}', [\App\Http\Controllers\InternalChatController::class, 'attachment'])->name('internal-chat.attachment');

// --- WhatsApp Webhook (Meta) ---
Route::get('whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('whatsapp/webhook', [WhatsAppWebhookController::class, 'handle']);

// --- External API for n8n ---
Route::middleware('api_key')->prefix('external/whatsapp')->group(function () {
    Route::get('templates', [\App\Http\Controllers\External\ExternalWhatsAppController::class, 'index']);
    Route::post('send', [\App\Http\Controllers\External\ExternalWhatsAppController::class, 'send']);
    Route::match(['get', 'post'], 'check-window', [\App\Http\Controllers\External\ExternalWhatsAppController::class, 'checkWindow']);
    Route::get('media-library', [\App\Http\Controllers\External\ExternalWhatsAppController::class, 'listMedia']);
});

// Endpoints
Route::middleware(['auth:sanctum', 'agency.gate'])->group(function () {
    Route::get('/kanban-data', [OrderController::class, 'kanban']);

    /**---------------------
     * CHAT INTERNO POR ORDEN (vendedora <-> agencia)
     * ---------------------**/
    Route::prefix('internal-chat')->group(function () {
        Route::get('/conversations', [\App\Http\Controllers\InternalChatController::class, 'conversations']);
        Route::get('/unread-count', [\App\Http\Controllers\InternalChatController::class, 'unreadCount']);
        Route::get('/gate-status', [\App\Http\Controllers\InternalChatController::class, 'gateStatus']);
        Route::get('/orders/search', [\App\Http\Controllers\InternalChatController::class, 'searchOrders']);
        Route::post('/orders/{order}/open', [\App\Http\Controllers\InternalChatController::class, 'openByOrder']);
        Route::get('/orders/{order}', [\App\Http\Controllers\InternalChatController::class, 'byOrder']); // el hilo dentro de la ficha
        Route::get('/conversations/{conversation}/messages', [\App\Http\Controllers\InternalChatController::class, 'messages']);
        Route::post('/conversations/{conversation}/messages', [\App\Http\Controllers\InternalChatController::class, 'store']);
        Route::post('/conversations/{conversation}/read', [\App\Http\Controllers\InternalChatController::class, 'markRead']);
    });
    /**---------------------
     * USERS
     * ---------------------**/
    // Listar usuarios
    Route::get('users', [AuthController::class, 'get_all_users'])->middleware('role:Admin,Gerente,Master');
    // Crear usuario
    Route::post('users', [AuthController::class, 'store'])->middleware('role:Admin,Gerente,Master');
    // Editar datos del usuario
    Route::put('user/{user}', [AuthController::class, 'edit_user_data']);
    // Validar token y obtener datos del usuario logueado
    Route::get('user/data', [AuthController::class, 'get_logged_user_data']);
    
    // Dashboard data
    // Dashboard data
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index']);
    
    // 🔥 CLIENT REQUEST: Payment reports by method
    Route::get('/reports/payments-by-method', [\App\Http\Controllers\PaymentReportController::class, 'paymentsByMethod'])->middleware('role:Admin,Gerente,Master');

    // ⏱️ Delivered Orders Hours Report (Admin only, temporary)
    Route::get('/reports/delivered-hours', [\App\Http\Controllers\DeliveredOrdersReportController::class, 'index']);

    // Comprehensive Tracking Report
    Route::get('/reports/tracking-comprehensive', [OrderTrackingComprehensiveController::class, 'index']);
    Route::get('/reports/tracking-comprehensive/filters', [OrderTrackingComprehensiveController::class, 'getFilters']);
    Route::get('/reports/tracking-comprehensive/cohort-metrics', [OrderTrackingComprehensiveController::class, 'cohortMetrics']);

    // ── Fase 6: Valoración de Inventario ─────────────────────────────────────
    Route::get('/reports/inventory-valuation', [\App\Http\Controllers\InventoryValuationController::class, 'valuation']);
    Route::get('/reports/profitability', [\App\Http\Controllers\InventoryValuationController::class, 'profitability']);

    // ── Exportaciones ─────────────────────────────────────────────────────────
    // Órdenes por rango de fechas (?from=YYYY-MM-DD&to=YYYY-MM-DD)
    Route::get('/reports/orders-export', [\App\Http\Controllers\OrdersExportController::class, 'index']);
    // Stock actual con lead times
    Route::get('/reports/stock-export', [\App\Http\Controllers\StockExportController::class, 'stockExport']);
    // Lead times por producto/almacén
    Route::get('/product-warehouse-lead-times', [\App\Http\Controllers\StockExportController::class, 'listLeadTimes']);
    Route::put('/product-warehouse-lead-times', [\App\Http\Controllers\StockExportController::class, 'upsertLeadTime']);

    // Statuses
    Route::get('/statuses', [StatusController::class, 'index']);

    // Cambiar color
    Route::put('user/{user}/change/color', [AuthController::class, 'edit_color']);
    // Cambiar tema
    Route::put('user/{user}/change/theme', [AuthController::class, 'edit_theme']);
    // Cambiar password
    Route::put('user/{user}/change/password', [AuthController::class, 'edit_password']);
    // Registrar agente
    Route::post('register/agent', [AuthController::class, 'register_agent'])->middleware('role:Admin,Gerente,Master');
    // Cerrar sesion
    Route::get('logout', [AuthController::class, 'logout']);
    // Listar agentes
    Route::get('users/agents', [AuthController::class, 'agents']);
    Route::get('users/role/{role}', [AuthController::class, 'usersByRole']);
    // Asignar agente a la orden
    Route::put('orders/{order}/assign-agent', [OrderController::class, 'assignAgent'])->middleware('role:Admin,Gerente,Master');
    Route::put('orders/{order}/assign-agency', [OrderController::class, 'assignAgency'])->middleware('order.access');
    
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
    Route::post('status', [StatusController::class, 'create'])->middleware('role:Admin,Master');
    /**---------------------
     * ROLES
     * ---------------------**/
    // Crear rol nuevo
    Route::post('role', [RoleController::class, 'create'])->middleware('role:Admin,Master');
    // Listar roles
    Route::get('roles', [RoleController::class, 'index']);
    // Repartidores: listar lo usan varios roles; crear/editar/eliminar solo Admin (pantalla /deliverers)
    Route::get('/users/deliverers', [AuthController::class, 'deliverers']);       // listar + buscar
    Route::post('/users/deliverers', [AuthController::class, 'storeDeliverer'])->middleware('role:Admin,Master');  // crear
    Route::put('/users/deliverers/{user}', [AuthController::class, 'updateDeliverer'])->middleware('role:Admin,Master'); // editar
    Route::delete('/users/deliverers/{user}', [AuthController::class, 'destroyDeliverer'])->middleware('role:Admin,Master'); // eliminar

    Route::get('/products', [ProductController::class, 'index']);

    Route::put('/orders/{order}/assign-deliverer', [OrderDelivererController::class, 'assign'])->middleware('order.access');

    /**---------------------
     * ORDERS
     * ---------------------**/
    Route::put('/orders/{order}/status', [OrderController::class, 'updateStatus'])->middleware('order.access');
    Route::get('/orders/{order}/available-statuses', [OrderController::class, 'getAvailableStatuses'])->middleware('order.access');
    Route::put('/orders/{order}/logistics', [OrderController::class, 'updateLogistics'])->middleware('order.access');
    // Auto-asignación masiva de logística
    Route::post('/orders/auto-assign-logistics', [OrderController::class, 'autoAssignAllLogistics']);
    Route::get('/orders/pending-vueltos', [OrderController::class, 'getPendingVueltos']);
    // Vueltos de la agencia con pago mixto: administración los valida (Fran, 2026-10-03)
    Route::get('/orders/change-approvals', [\App\Http\Controllers\OrderChangeApprovalController::class, 'index'])->middleware('role:Admin,Gerente,Master');
    Route::post('/orders/{order}/change-approval', [\App\Http\Controllers\OrderChangeApprovalController::class, 'decide'])->middleware('role:Admin,Gerente,Master');
    Route::get('/orders/lite/counts', [OrderController::class, 'liteCounts']); // Lite Dashboard Counts
    // Ver detalles de la orden
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    // Agregar nota a la orden
    Route::post('/orders/{order}/updates', [OrderUpdateController::class, 'store'])->middleware('order.access');
    // Solicitar cancelación
    Route::post('orders/{order}/cancel', [OrderCancellationController::class, 'store'])->middleware('order.access');
    // Obtener productos de la orden
    Route::get('orders/{id}/products', [OrderController::class, 'getOrderProducts']);
    // Listar ordenes
    Route::get('orders', [OrderController::class, 'index']);
    // Crear orden manualmente
    Route::post('orders', [OrderController::class, 'store']);
    // Historial de actividades (Audit log)
    Route::get('orders/{order}/activities', [OrderController::class, 'getActivityLogs']);
    // Upselling
    Route::get('orders/{order}/upsell-options', [OrderController::class, 'upsellOptions'])->middleware('order.access');
    Route::post('orders/{order}/upsell', [OrderController::class, 'addUpsell'])->middleware('order.access');
    Route::delete('orders/{order}/upsell/{itemId}', [OrderController::class, 'removeUpsell'])->middleware('order.access');
    Route::put('orders/{order}/upsell/{itemId}', [OrderController::class, 'updateProductQuantity'])->middleware('order.access');
    // 🔥 CLIENT REQUEST: Admin can manually edit order total
    Route::put('orders/{order}/total', [OrderController::class, 'updateTotal'])->middleware('order.access');
    // Productos
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{id}', [ProductController::class, 'show']);
    Route::put('products/{id}', [ProductController::class, 'update']);
    Route::post('products/{id}/gallery', [ProductController::class, 'uploadImage']);
    Route::delete('products/{id}/gallery/{imageId}', [ProductController::class, 'deleteImage']);

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
    Route::put('cancellations/{cancellation}/review', [OrderCancellationController::class, 'review'])->middleware('role:Admin,Gerente,Master');
    // Listar cancelaciones
    Route::get('cancellations', [OrderCancellationController::class, 'index'])->middleware('role:Admin,Gerente,Master');

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
    Route::post('orders/auto-assign-cities', [App\Http\Controllers\OrderController::class, 'autoAssignCities'])->middleware('role:Admin,Gerente,Master');

    Route::get('/settings/business-hours', [SettingsController::class, 'getBusinessHours']);
    Route::put('/settings/business-hours', [SettingsController::class, 'updateBusinessHours']);

    // ── Módulo 5, entrega 1: stock real y traslados con tránsito (§14, §15, §92, R1, R2) ──
    // Crear y ver traslados: como el traslado de siempre (la agencia, desde su almacén y viendo los suyos)
    Route::get('/inventory-transfers', [\App\Http\Controllers\InventoryTransferController::class, 'index']);
    Route::post('/inventory-transfers', [\App\Http\Controllers\InventoryTransferController::class, 'store']);
    Route::middleware('role:Admin,Gerente,Master')->group(function () {
        Route::get('/inventory/stock-status', [\App\Http\Controllers\InventoryStockController::class, 'index']);
        Route::post('/inventory-transfers/{transfer}/send', [\App\Http\Controllers\InventoryTransferController::class, 'send']);
        Route::post('/inventory-transfers/{transfer}/receive', [\App\Http\Controllers\InventoryTransferController::class, 'receive']);
        Route::post('/inventory-transfers/{transfer}/cancel', [\App\Http\Controllers\InventoryTransferController::class, 'cancel']);
    });

    // ── Reparto de órdenes por grupos (fase 4) ────────────────────────────────
    Route::middleware('role:Admin,Gerente,Master')->group(function () {
        Route::get('/assignment/overview',          [AssignmentController::class, 'overview']);
        Route::post('/assignment/reset',            [AssignmentController::class, 'reset']);
        Route::get('/assignment/reassign/preview',  [AssignmentController::class, 'reassignPreview']);
        Route::post('/assignment/reassign',         [AssignmentController::class, 'reassign']);
    });
    // Grupos, % y máximos: solo el Admin (Fran, segunda ronda §2)
    Route::middleware('role:Admin,Master')->group(function () {
        Route::get('/sales-groups',                        [SalesGroupController::class, 'index']);
        Route::post('/sales-groups',                       [SalesGroupController::class, 'store']);
        Route::put('/sales-groups/{salesGroup}',           [SalesGroupController::class, 'update']);
        Route::delete('/sales-groups/{salesGroup}',        [SalesGroupController::class, 'destroy']);
        Route::put('/sales-groups/{salesGroup}/members',   [SalesGroupController::class, 'members']);
        Route::put('/sales-groups/{salesGroup}/weights',   [SalesGroupController::class, 'weights']);
        Route::get('/sales-groups/notes',                  [SalesGroupController::class, 'notes']);
        Route::put('/assignment/sellers/{user}',           [AssignmentController::class, 'updateSeller']);
    });
    // "Mi grupo" de la Líder: el controlador comprueba que lidera un grupo activo y limita todo a ese grupo.
    Route::prefix('my-group')->group(function () {
        Route::get('/',                  [MyGroupController::class, 'show']);
        Route::get('/metrics',           [MyGroupController::class, 'metrics']);
        Route::get('/agencies',          [MyGroupController::class, 'agencies']);
        Route::get('/notes',             [MyGroupController::class, 'notes']);
        Route::post('/notes',            [MyGroupController::class, 'storeNote']);
        Route::get('/report',            [MyGroupController::class, 'report']);
        Route::put('/report',            [MyGroupController::class, 'saveReport']);
        Route::get('/meetings',          [MyGroupController::class, 'meetings']);
        Route::post('/meetings',         [MyGroupController::class, 'storeMeeting']);
        Route::get('/meetings/{meeting}/file', [MyGroupController::class, 'meetingFile']);
        Route::delete('/meetings/{meeting}',   [MyGroupController::class, 'destroyMeeting']);
        Route::put('/weights',           [MyGroupController::class, 'weights']);
        Route::put('/roster',            [MyGroupController::class, 'roster']);
        Route::get('/reassign/preview',  [MyGroupController::class, 'reassignPreview']);
        Route::post('/reassign',         [MyGroupController::class, 'reassign']);
        Route::put('/orders/{order}/agent', [MyGroupController::class, 'moveOrder']); // un pedido suelto (H4)
    });
    Route::put('/settings/strategy',             [SettingsController::class, 'updateStrategy']);
    Route::get('/business/today', [BusinessController::class, 'status']); // estado actual
    Route::get('/business/status', [BusinessController::class, 'status']); // estado actual
    Route::post('/business/open',   [BusinessController::class, 'open']);   // abrir jornada (ahora)
    Route::post('/business/close',  [BusinessController::class, 'close']);  // cerrar jornada (ahora)
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/by-city', [\App\Http\Controllers\CityStockController::class, 'index']); // por ciudad, sin agencias (Fran 30-sep)
    Route::put('/inventory/{product}/adjust', [InventoryController::class, 'adjust']); // IN/OUT
    Route::post('/inventory/defective/resolve', [\App\Http\Controllers\DefectiveStockController::class, 'resolve']); // piezas defectuosas: vuelven a la venta o se dan de baja (Fran 2-oct)
    Route::post('/inventory/defective/intake', [\App\Http\Controllers\DefectiveStockController::class, 'intake']); // dañada o perdida: pasa a revisión (Módulo 4, R8)

    Route::get('/deliverer/stock/today', [DelivererStockController::class, 'mineToday']); // para repartidor
    Route::post('/deliverer/stock/assign', [DelivererStockController::class, 'assign']);
    Route::post('/deliverer/stock/return', [DelivererStockController::class, 'return']);

    Route::get('/commissions/me/today', [CommissionController::class, 'myToday']);
    Route::get('/commissions/admin/summary', [CommissionController::class, 'adminSummary']); // ?from=YYYY-MM-DD&to=...
    // Productos
    Route::get('/inventory/products', [ProductController::class, 'index']);
    Route::post('/inventory/products', [ProductController::class, 'store']);
    Route::put('/inventory/products/{id}', [ProductController::class, 'update']);
    // Variantes (tallas, colores) de cada producto (tarea 4)
    Route::get('/products/{product}/variants', [\App\Http\Controllers\ProductVariantController::class, 'index']);
    Route::post('/products/{product}/variants', [\App\Http\Controllers\ProductVariantController::class, 'store'])->middleware('role:Admin,Gerente,Master');
    Route::put('/product-variants/{variant}', [\App\Http\Controllers\ProductVariantController::class, 'update'])->middleware('role:Admin,Gerente,Master');
    Route::delete('/product-variants/{variant}', [\App\Http\Controllers\ProductVariantController::class, 'destroy'])->middleware('role:Admin,Gerente,Master');

    // Movimientos de stock
    Route::get('/stock/movements', [StockMovementController::class, 'index']); // opcional: filtro por fechas/sku
    Route::post('/stock/adjust', [StockMovementController::class, 'adjust']);  // IN/OUT
    Route::post('/stock/movements', [StockMovementController::class, 'store']);
    Route::get('/inventory', [InventoryController::class, 'index']);      // Admin / Gerente
    Route::get('/inventory/my', [InventoryController::class, 'myStock']); // Repartidor

    // ─── SCM: Supply Chain Management ─────────────────────────────────────────
    Route::prefix('scm')->group(function () {
        Route::get('/dashboard', [SupplyChainController::class, 'dashboard']);
        Route::put('/inventory/{id}', [SupplyChainController::class, 'updateInventoryScm']);
        Route::put('/products/{id}', [SupplyChainController::class, 'updateProductScm']);
    });
    Route::get('/deliverer/stock/today', [DelivererStockController::class, 'mineToday']);
    Route::post('/deliverer/stock/open', [DelivererStockController::class, 'open']); // abre jornada y asigna items

    // ─── FASE 5: Proveedores y Órdenes de Compra ──────────────────────────────
    Route::apiResource('suppliers', \App\Http\Controllers\SupplierController::class);

    Route::prefix('purchase-orders')->group(function () {
        Route::get('/',              [\App\Http\Controllers\PurchaseOrderController::class, 'index']);
        Route::post('/',             [\App\Http\Controllers\PurchaseOrderController::class, 'store']);
        Route::get('/{id}',          [\App\Http\Controllers\PurchaseOrderController::class, 'show']);
        Route::put('/{id}',          [\App\Http\Controllers\PurchaseOrderController::class, 'update']);
        Route::post('/{id}/status',  [\App\Http\Controllers\PurchaseOrderController::class, 'updateStatus']);
        Route::post('/{id}/receive', [\App\Http\Controllers\PurchaseOrderController::class, 'receive']);
        Route::post('/{id}/cancel',  [\App\Http\Controllers\PurchaseOrderController::class, 'cancel']);
    });

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

    Route::put('/orders/{order}/payment', [OrderController::class, 'updatePayment'])->middleware('order.access');
    Route::put('/orders/{order}/change', [OrderController::class, 'updateChange'])->middleware('order.access');
    Route::get('/order/{order}/products', [OrderController::class, 'getOrderProducts']);
    Route::put('/orders/{order}/location', [OrderController::class, 'addLocation'])->middleware('order.access');
    Route::put('/orders/{order}/courier', [OrderController::class, 'updateCourier'])->middleware('order.access');
    // Bancos: cualquiera los lista (vueltos/pagos); solo supervisión los edita
    Route::apiResource('banks', BankController::class)->only(['index', 'show']);
    Route::apiResource('banks', BankController::class)->except(['index', 'show'])->middleware('role:Admin,Gerente,Master');

    Route::post('/orders/{order}/payment-receipt', [OrderController::class, 'uploadPaymentReceipt'])->middleware('order.access');
    Route::delete('/orders/{order}/payment-receipt/{receiptId}', [OrderController::class, 'deletePaymentReceipt'])->middleware('order.access');
    // Revisión de comprobantes con IA (Fran, 2026-10-03)
    Route::get('/orders/{order}/receipt-checks', [\App\Http\Controllers\ReceiptCheckController::class, 'forOrder'])->middleware('order.access');
    Route::post('/orders/{order}/receipt-checks/{check}/retry', [\App\Http\Controllers\ReceiptCheckController::class, 'retry'])->middleware('order.access');
    Route::post('/orders/{order}/receipt-checks/{check}/approve', [\App\Http\Controllers\ReceiptCheckController::class, 'approve'])->middleware('role:Admin,Gerente,Master');
    Route::get('/receipt-checks', [\App\Http\Controllers\ReceiptCheckController::class, 'index'])->middleware('role:Admin,Gerente,Master');
    Route::post('/orders/{order}/change-receipt', [OrderController::class, 'uploadChangeReceipt'])->middleware('order.access');
    Route::put('/orders/{order}/reminder', [OrderController::class, 'setReminder'])->middleware('order.access');
    Route::put('/orders/{order}/toggle-notification', [OrderController::class, 'toggleChangeNotification'])->middleware('order.access');

    /**---------------------
     * WHATSAPP MESSAGES
     * ---------------------**/
    Route::get('/whatsapp-templates/meta', [\App\Http\Controllers\WhatsappTemplateController::class, 'metaTemplates']);
    Route::post('/whatsapp-templates/import-meta', [\App\Http\Controllers\WhatsappTemplateController::class, 'importFromMeta']);
    Route::put('/whatsapp-templates/{id}', [\App\Http\Controllers\WhatsappTemplateController::class, 'update']);
    Route::delete('/whatsapp-templates/{id}', [\App\Http\Controllers\WhatsappTemplateController::class, 'destroy']);
    Route::apiResource('whatsapp-templates', \App\Http\Controllers\WhatsappTemplateController::class)->except(['update', 'destroy']);
    Route::get('/orders/{order}/whatsapp-messages', [\App\Http\Controllers\WhatsappMessageController::class, 'index'])->middleware('order.access');
    Route::post('/orders/{order}/whatsapp-messages', [\App\Http\Controllers\WhatsappMessageController::class, 'store'])->middleware('order.access');
    Route::post('/orders/{order}/whatsapp-media', [\App\Http\Controllers\WhatsappMessageController::class, 'sendMedia'])->middleware('order.access');
    Route::put('/orders/{order}/read-whatsapp', [\App\Http\Controllers\WhatsappMessageController::class, 'markAsRead'])->middleware('order.access');

    // --- CENTRALIZED WHATSAPP VIEW (NEW) ---
    Route::get('/whatsapp-conversations', [\App\Http\Controllers\WhatsappConversationController::class, 'index']);
    Route::get('/whatsapp-conversations/{client}/messages', [\App\Http\Controllers\WhatsappConversationController::class, 'show']);
    Route::post('/whatsapp-conversations/{client}/messages', [\App\Http\Controllers\WhatsappConversationController::class, 'store']);
    Route::post('/whatsapp-conversations/{client}/media', [\App\Http\Controllers\WhatsappConversationController::class, 'sendMedia']);
    Route::post('/whatsapp-conversations/{client}/read', [\App\Http\Controllers\WhatsappConversationController::class, 'markAsRead']);
    Route::post('/whatsapp-conversations/{client}/move', [\App\Http\Controllers\WhatsappConversationController::class, 'moveToBucket']);
    Route::post('/whatsapp-conversations/{conversation}/assign', [\App\Http\Controllers\WhatsappConversationController::class, 'assignAgent']);

    // --- WHATSAPP CRM v2 (Lógica de permisos nueva — simple y robusta) ---
    Route::prefix('whatsapp-crm')->group(function () {
        Route::get('/conversations',                          [\App\Http\Controllers\WhatsappCrmController::class, 'index']);
        Route::get('/conversations/{clientId}/messages',      [\App\Http\Controllers\WhatsappCrmController::class, 'show']);
        Route::post('/conversations/{clientId}/messages',     [\App\Http\Controllers\WhatsappCrmController::class, 'store']);
        Route::post('/conversations/{clientId}/media',        [\App\Http\Controllers\WhatsappCrmController::class, 'sendMedia']);
        Route::post('/conversations/{clientId}/read',         [\App\Http\Controllers\WhatsappCrmController::class, 'markAsRead']);
        Route::post('/conversations/{clientId}/move',         [\App\Http\Controllers\WhatsappCrmController::class, 'moveToBucket']);
        Route::post('/conversations/{clientId}/assign',       [\App\Http\Controllers\WhatsappCrmController::class, 'assignAgent']);
    });

    // --- Media Explorer (Filesystem) ---
    Route::get('/media-explorer', [\App\Http\Controllers\MediaExplorerController::class, 'index']);
    Route::post('/media-explorer/mkdir', [\App\Http\Controllers\MediaExplorerController::class, 'mkdir']);
    Route::post('/media-explorer/upload', [\App\Http\Controllers\MediaExplorerController::class, 'upload']);
    Route::post('/media-explorer/rename', [\App\Http\Controllers\MediaExplorerController::class, 'rename']);
    Route::post('/media-explorer/delete', [\App\Http\Controllers\MediaExplorerController::class, 'delete']);

    // --- CRM Assignment & Shift Management (Automated Roster) ---
    Route::get('/crm/agents', [\App\Http\Controllers\CrmAssignmentController::class, 'index']);
    Route::post('/crm/agents/{id}/toggle', [\App\Http\Controllers\CrmAssignmentController::class, 'toggleStatus']);
    Route::post('/crm/clients/{clientId}/assign', [\App\Http\Controllers\CrmAssignmentController::class, 'assign']);

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

    Route::post('/orders/{order}/postpone', [OrderPostponementController::class, 'store'])->middleware('order.access');
    Route::post('/orders/{order}/create-return', [OrderController::class, 'createReturn'])->middleware('order.access');

    // Devoluciones = reembolsos (tarea 3b) y carreras de las agencias (tarea 5)
    Route::get('/orders/{order}/refunds', [\App\Http\Controllers\OrderRefundController::class, 'index'])->middleware('order.access');
    Route::post('/orders/{order}/refunds', [\App\Http\Controllers\OrderRefundController::class, 'store'])->middleware('order.access');
    Route::get('/orders/{order}/refunds/{refund}/receipt', [\App\Http\Controllers\OrderRefundController::class, 'receipt'])->middleware('order.access');
    // Las carreras solo las ve el Admin; la agencia no (Fran, 2026-10-06)
    Route::get('/orders/{order}/trips', [\App\Http\Controllers\AgencyTripController::class, 'index'])->middleware('role:Admin,Master');
    Route::post('/agency-trips/{trip}/void', [\App\Http\Controllers\AgencyTripController::class, 'void'])->middleware('role:Admin,Master');

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
        Route::post('/write-off', [InventoryMovementController::class, 'writeOff']); // Módulo 5, §94: muestra, regalo, uso interno
        Route::post('/{id}/confirm', [InventoryMovementController::class, 'confirm']);
        Route::post('/{id}/reject', [InventoryMovementController::class, 'reject']);
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
    Route::get('metrics', [\App\Http\Controllers\MetricsController::class, 'index'])->middleware('role:Admin,Gerente,Master');
    Route::get('business-metrics', [\App\Http\Controllers\BusinessMetricsController::class, 'index'])->middleware('role:Admin,Master');
    Route::post('metrics/ad-spend', [\App\Http\Controllers\MetricsController::class, 'storeAdSpend'])->middleware('role:Admin,Gerente,Master');


    /**---------------------
     * CITIES (lectura para todos; ciudades, agencias y precio por carrera solo los edita supervisión)
     * ---------------------**/
    Route::apiResource('cities', CityController::class)->only(['index', 'show']);
    Route::apiResource('cities', CityController::class)->except(['index', 'show'])->middleware('role:Admin,Gerente,Master');

    // Varias agencias por ciudad, su % y su máximo, y la reasignación en bloque (tareas 3c, 6 y 8)
    Route::get('agency-routing', [\App\Http\Controllers\AgencyRoutingController::class, 'index'])->middleware('role:Admin,Gerente,Master');
    Route::put('agency-routing/agencies/{agency}', [\App\Http\Controllers\AgencyRoutingController::class, 'updateAgency'])->middleware('role:Admin,Master');
    Route::put('agency-routing/cities/{city}', [\App\Http\Controllers\AgencyRoutingController::class, 'updateCity'])->middleware('role:Admin,Master');
    Route::put('agency-routing/cities/{city}/working', [\App\Http\Controllers\AgencyRoutingController::class, 'updateCityWorking'])->middleware('role:Admin,Master');
    Route::put('agency-routing/agencies/{agency}/hours', [\App\Http\Controllers\AgencyRoutingController::class, 'updateAgencyHours'])->middleware('role:Admin,Master');
    Route::get('agency-routing/agencies/{agency}/reassign', [\App\Http\Controllers\AgencyRoutingController::class, 'reassignPreview'])->middleware('role:Admin,Gerente,Master');
    Route::post('agency-routing/agencies/{agency}/reassign', [\App\Http\Controllers\AgencyRoutingController::class, 'reassign'])->middleware('role:Admin,Gerente,Master');

    /**---------------------
     * PROVINCES
     * ---------------------**/
    Route::apiResource('provinces', \App\Http\Controllers\ProvinceController::class)->only(['index', 'show']);
    Route::apiResource('provinces', \App\Http\Controllers\ProvinceController::class)->except(['index', 'show'])->middleware('role:Admin,Master');

    /**---------------------
     * COMPANY ACCOUNTS (las vendedoras las ven en la orden; solo supervisión las edita)
     * ---------------------**/
    Route::get('company-accounts-options', [CompanyAccountController::class, 'options'])->middleware('role:Admin,Gerente,Master');
    Route::apiResource('company-accounts', CompanyAccountController::class)->only(['index', 'show']);
    Route::apiResource('company-accounts', CompanyAccountController::class)->except(['index', 'show'])->middleware('role:Admin,Gerente,Master');

    /**---------------------
     * CONCILIACIÓN de pagos digitales con los extractos (documento de Fran del 2026-10-06, Módulo 1). Los extractos
     * tienen movimientos financieros y personales: solo el Administrador (§29).
     * ---------------------**/
    Route::middleware('role:Admin')->group(function () {
        Route::get('/reconciliations', [\App\Http\Controllers\ReconciliationController::class, 'index']);
        Route::get('/reconciliations/{date}', [\App\Http\Controllers\ReconciliationController::class, 'show']);
        Route::post('/reconciliations/{date}/statements', [\App\Http\Controllers\ReconciliationController::class, 'upload']);
        Route::get('/reconciliation-statements/{statement}/file', [\App\Http\Controllers\ReconciliationController::class, 'file']);
        Route::post('/reconciliation-items/{item}/resolve', [\App\Http\Controllers\ReconciliationController::class, 'resolve']);
        Route::get('/reconciliation-items/{item}/rows', [\App\Http\Controllers\ReconciliationController::class, 'rows']);
    });

    /**---------------------
     * META ADS (documento de Fran del 2026-10-08, Módulo 2). Credenciales de Meta, gasto y métricas del negocio: solo el
     * Administrador (§44). Todo es lectura de Meta: nada de esto modifica campañas, presupuestos ni anuncios (§1).
     * ---------------------**/
    Route::middleware('role:Admin')->prefix('meta')->group(function () {
        Route::get('/connections', [\App\Http\Controllers\MetaConnectionController::class, 'index']);
        Route::post('/connections', [\App\Http\Controllers\MetaConnectionController::class, 'store']);
        Route::get('/connections/{connection}', [\App\Http\Controllers\MetaConnectionController::class, 'show']);
        Route::put('/connections/{connection}', [\App\Http\Controllers\MetaConnectionController::class, 'update']);
        Route::post('/connections/{connection}/accounts/refresh', [\App\Http\Controllers\MetaConnectionController::class, 'refreshAccounts']);
        Route::get('/connections/{connection}/logs', [\App\Http\Controllers\MetaConnectionController::class, 'logs']);
        Route::post('/connections/{connection}/sync', [\App\Http\Controllers\MetaConnectionController::class, 'sync']);
        Route::put('/ad-accounts/{account}', [\App\Http\Controllers\MetaConnectionController::class, 'updateAccount']);

        Route::get('/options', [\App\Http\Controllers\MetaAdsSetupController::class, 'options']);
        Route::get('/campaigns', [\App\Http\Controllers\MetaAdsSetupController::class, 'campaigns']);
        Route::put('/campaigns/{campaign}', [\App\Http\Controllers\MetaAdsSetupController::class, 'classify']);
        Route::put('/ads/{ad}/tracking-id', [\App\Http\Controllers\MetaAdsSetupController::class, 'setTrackingId']);
        Route::put('/creatives/{creative}/status', [\App\Http\Controllers\MetaAdsSetupController::class, 'setCreativeStatus']);
        Route::get('/targets', [\App\Http\Controllers\MetaAdsSetupController::class, 'targets']);
        Route::post('/targets', [\App\Http\Controllers\MetaAdsSetupController::class, 'storeTarget']);
        Route::get('/rules', [\App\Http\Controllers\MetaAdsSetupController::class, 'rules']);
        Route::post('/rules', [\App\Http\Controllers\MetaAdsSetupController::class, 'storeRule']);
        Route::put('/rules/{rule}', [\App\Http\Controllers\MetaAdsSetupController::class, 'updateRule']);
        Route::delete('/rules/{rule}', [\App\Http\Controllers\MetaAdsSetupController::class, 'deleteRule']);

        Route::get('/report/overview', [\App\Http\Controllers\MetaReportController::class, 'overview']);
        Route::get('/report/rows', [\App\Http\Controllers\MetaReportController::class, 'rows']);
        Route::get('/report/series', [\App\Http\Controllers\MetaReportController::class, 'series']);
        Route::get('/report/filters', [\App\Http\Controllers\MetaReportController::class, 'filters']);
        Route::get('/report/creatives/{creative}', [\App\Http\Controllers\MetaReportController::class, 'creative']);
    });

    /**---------------------
     * TARIFAS DE DELIVERY (documento de Fran del 2026-10-09, Módulo 3). Almacenes con coordenadas, tarifarios y el
     * costo esperado de cada carrera: solo administración, controlado aquí y no en la pantalla (§14). La agencia no
     * puede ver ni el tarifario, ni la distancia, ni el precio calculado.
     * ---------------------**/
    Route::middleware('role:Admin')->prefix('delivery')->group(function () {
        Route::get('/warehouses', [\App\Http\Controllers\DeliveryTariffController::class, 'warehouses']);
        Route::put('/warehouses/{warehouse}', [\App\Http\Controllers\DeliveryTariffController::class, 'updateWarehouse']);
        Route::post('/resolve-link', [\App\Http\Controllers\DeliveryTariffController::class, 'resolveLink']);
        Route::get('/tariffs', [\App\Http\Controllers\DeliveryTariffController::class, 'tariffs']);
        Route::post('/tariffs', [\App\Http\Controllers\DeliveryTariffController::class, 'storeTariff']);
        Route::get('/routes', [\App\Http\Controllers\DeliveryTariffController::class, 'routes']);
        Route::post('/routes/{deliveryRoute}/retry', [\App\Http\Controllers\DeliveryTariffController::class, 'retryRoute']);
        Route::post('/routes/{deliveryRoute}/approve-distance', [\App\Http\Controllers\DeliveryTariffController::class, 'approveDistance']);

        // Cierres semanales de las agencias (§20 a §37): revisar, resolver, aprobar y pagar
        Route::get('/closures', [\App\Http\Controllers\AgencyClosureController::class, 'index']);
        Route::post('/closures', [\App\Http\Controllers\AgencyClosureController::class, 'store']);
        Route::get('/closures/settings', [\App\Http\Controllers\AgencyClosureController::class, 'settings']);
        Route::put('/closures/settings/{agency}', [\App\Http\Controllers\AgencyClosureController::class, 'updateSettings']);
        Route::get('/closures/{closure}', [\App\Http\Controllers\AgencyClosureController::class, 'show']);
        Route::post('/closures/{closure}/upload', [\App\Http\Controllers\AgencyClosureController::class, 'upload']);
        Route::post('/closures/{closure}/reprocess', [\App\Http\Controllers\AgencyClosureController::class, 'reprocess']);
        Route::get('/closures/{closure}/trips', [\App\Http\Controllers\AgencyClosureController::class, 'trips']);
        Route::post('/closures/{closure}/approve', [\App\Http\Controllers\AgencyClosureController::class, 'approve']);
        Route::post('/closures/{closure}/pay', [\App\Http\Controllers\AgencyClosureController::class, 'pay']);
        Route::get('/closures/{closure}/receipt', [\App\Http\Controllers\AgencyClosureController::class, 'receipt']);
        Route::get('/closure-files/{file}', [\App\Http\Controllers\AgencyClosureController::class, 'download']);
        Route::post('/closure-rows/{row}/link', [\App\Http\Controllers\AgencyClosureController::class, 'link']);
        Route::post('/closure-discrepancies/{discrepancy}/resolve', [\App\Http\Controllers\AgencyClosureController::class, 'resolve']);
        Route::post('/closure-discrepancies/{discrepancy}/reopen', [\App\Http\Controllers\AgencyClosureController::class, 'reopen']);
        Route::post('/closure-discrepancies/{discrepancy}/notes', [\App\Http\Controllers\AgencyClosureController::class, 'note']);
    });

    // Finanzas (Módulo 4 de Fran): solo el Admin (§81). Gastos manuales y recurrentes (§24 a §33) y su historial (§82)
    Route::middleware('role:Admin')->prefix('finance')->group(function () {
        Route::get('/options', [\App\Http\Controllers\FinanceExpenseController::class, 'options']);
        Route::post('/expense-categories', [\App\Http\Controllers\FinanceExpenseController::class, 'storeCategory']);
        Route::get('/expenses', [\App\Http\Controllers\FinanceExpenseController::class, 'index']);
        Route::post('/expenses', [\App\Http\Controllers\FinanceExpenseController::class, 'store']);
        // POST y no PUT: la corrección puede traer un comprobante nuevo, y PHP no lee archivos en un PUT
        Route::post('/expenses/{expense}', [\App\Http\Controllers\FinanceExpenseController::class, 'update']);
        Route::post('/expenses/{expense}/void', [\App\Http\Controllers\FinanceExpenseController::class, 'void']);
        Route::get('/expenses/{expense}/receipt', [\App\Http\Controllers\FinanceExpenseController::class, 'receipt']);
        Route::get('/recurring-expenses', [\App\Http\Controllers\FinanceExpenseController::class, 'recurringIndex']);
        Route::post('/recurring-expenses', [\App\Http\Controllers\FinanceExpenseController::class, 'recurringStore']);
        Route::put('/recurring-expenses/{recurring}', [\App\Http\Controllers\FinanceExpenseController::class, 'recurringUpdate']);
        Route::post('/recurring-expenses/{recurring}/versions', [\App\Http\Controllers\FinanceExpenseController::class, 'recurringVersion']);
        Route::get('/audit', [\App\Http\Controllers\FinanceExpenseController::class, 'audit']);
        // Rentabilidad por niveles y su detalle hasta el pedido (§47 a §52, §57, §58)
        Route::get('/summary', [\App\Http\Controllers\FinanceReportController::class, 'summary']);
        Route::get('/profitability', [\App\Http\Controllers\FinanceReportController::class, 'profitability']);
        Route::get('/profitability/orders', [\App\Http\Controllers\FinanceReportController::class, 'orders']);
        Route::get('/profitability/orders/{order}', [\App\Http\Controllers\FinanceReportController::class, 'order']);
        // Cohortes, efectividad, CPA financiero y configuración (§59 a §74, §22, §66)
        Route::get('/cohorts', [\App\Http\Controllers\FinanceCpaController::class, 'cohorts']);
        Route::get('/cpa', [\App\Http\Controllers\FinanceCpaController::class, 'cpa']);
        Route::post('/cpa/recalculate', [\App\Http\Controllers\FinanceCpaController::class, 'recalculate']);
        Route::get('/cpa/history', [\App\Http\Controllers\FinanceCpaController::class, 'history']);
        Route::get('/settings', [\App\Http\Controllers\FinanceCpaController::class, 'settings']);
        Route::put('/settings', [\App\Http\Controllers\FinanceCpaController::class, 'updateSettings']);
        Route::put('/product-margins/{product}', [\App\Http\Controllers\FinanceCpaController::class, 'updateMargin']);
        // Proveedores, compras y deudas (entrega 2b; §10, §16, §17, §44). La compra nace en el ingreso de inventario
        Route::get('/suppliers', [\App\Http\Controllers\FinanceSupplierController::class, 'index']);
        Route::post('/suppliers', [\App\Http\Controllers\FinanceSupplierController::class, 'store']);
        Route::get('/suppliers/{supplier}', [\App\Http\Controllers\FinanceSupplierController::class, 'show']);
        Route::put('/suppliers/{supplier}', [\App\Http\Controllers\FinanceSupplierController::class, 'update']);
        Route::get('/payables', [\App\Http\Controllers\FinanceSupplierController::class, 'payables']);
        Route::post('/payables/{payable}/payments', [\App\Http\Controllers\FinanceSupplierController::class, 'pay']);
        Route::post('/payable-payments/{payment}/void', [\App\Http\Controllers\FinanceSupplierController::class, 'voidPayment']);
        Route::get('/payable-payments/{payment}/receipt', [\App\Http\Controllers\FinanceSupplierController::class, 'paymentReceipt']);
        Route::post('/purchases/{purchase}/receipt', [\App\Http\Controllers\FinanceSupplierController::class, 'uploadPurchaseReceipt']);
        Route::post('/purchases/{purchase}/correct', [\App\Http\Controllers\FinanceSupplierController::class, 'correctPurchase']);
        // Módulo 5, entrega 2: configuración del plan de inventario (§5, §6, §12, §13, §17–20, §24, §60–65, §95, §128)
        Route::get('/replenishment/config', [\App\Http\Controllers\ReplenishmentConfigController::class, 'index']);
        Route::put('/replenishment/settings', [\App\Http\Controllers\ReplenishmentConfigController::class, 'updateSettings']);
        Route::put('/replenishment/products/{product}', [\App\Http\Controllers\ReplenishmentConfigController::class, 'updateProduct']);
        Route::put('/replenishment/products/{product}/cities/{city}', [\App\Http\Controllers\ReplenishmentConfigController::class, 'setAvailability']);
        Route::post('/replenishment/supplier-products', [\App\Http\Controllers\ReplenishmentConfigController::class, 'storeSupplierProduct']);
        Route::put('/replenishment/supplier-products/{supplierProduct}', [\App\Http\Controllers\ReplenishmentConfigController::class, 'updateSupplierProduct']);
        Route::post('/replenishment/routes', [\App\Http\Controllers\ReplenishmentConfigController::class, 'storeRoute']);
        Route::put('/replenishment/routes/{route}', [\App\Http\Controllers\ReplenishmentConfigController::class, 'updateRoute']);
        Route::get('/replenishment/audit', [\App\Http\Controllers\ReplenishmentConfigController::class, 'audit']);
        // Entrega 3: el plan (§4, §96)
        Route::get('/replenishment/plans', [\App\Http\Controllers\ReplenishmentPlanController::class, 'index']);
        Route::post('/replenishment/plans', [\App\Http\Controllers\ReplenishmentPlanController::class, 'store']);
        Route::get('/replenishment/plans/{forecast}', [\App\Http\Controllers\ReplenishmentPlanController::class, 'show'])->whereNumber('forecast');
        // Entrega 4: aprobar (§89–§92), PDF del chofer (§112–§120) y precisión (§97–§105)
        Route::post('/replenishment/plans/{forecast}/approve', [\App\Http\Controllers\ReplenishmentPlanController::class, 'approve'])->whereNumber('forecast');
        Route::get('/replenishment/plans/{forecast}/route-pdf', [\App\Http\Controllers\ReplenishmentPlanController::class, 'routePdf'])->whereNumber('forecast');
        Route::get('/replenishment/evaluations', [\App\Http\Controllers\ReplenishmentPlanController::class, 'evaluations']);
        // Compras por recibir (Módulo 5, entrega 1; §16, §90, §91, R3)
        Route::get('/purchases', [\App\Http\Controllers\FinancePurchaseController::class, 'index']);
        Route::post('/purchases', [\App\Http\Controllers\FinancePurchaseController::class, 'store']);
        Route::post('/purchases/{purchase}/receive', [\App\Http\Controllers\FinancePurchaseController::class, 'receive']);
        Route::post('/purchases/{purchase}/cancel', [\App\Http\Controllers\FinancePurchaseController::class, 'cancel']);
        // Finanzas › Inventario (entrega 2e): valor FIFO, lotes, por completar, revisión, ajustes y cuadre
        Route::get('/inventory', [\App\Http\Controllers\FinanceInventoryController::class, 'index']);
        Route::put('/lots/{lot}/cost', [\App\Http\Controllers\FinanceInventoryController::class, 'updateCost']);
        Route::get('/purchases/{purchase}/receipt', [\App\Http\Controllers\FinanceSupplierController::class, 'purchaseReceipt']);
        // Viajes de traslado (entrega 2c; §13, §14, §15, R8)
        Route::get('/trips', [\App\Http\Controllers\FinanceTripController::class, 'index']);
        Route::get('/trips/candidates', [\App\Http\Controllers\FinanceTripController::class, 'candidates']);
        Route::post('/trips', [\App\Http\Controllers\FinanceTripController::class, 'store']);
        Route::post('/trips/{trip}', [\App\Http\Controllers\FinanceTripController::class, 'update']);
        Route::post('/trips/{trip}/void', [\App\Http\Controllers\FinanceTripController::class, 'void']);
        Route::get('/trips/{trip}/receipt', [\App\Http\Controllers\FinanceTripController::class, 'receipt']);
        // Caja (entrega 3; §34 a §36, §42, §75, §76, R14): cuentas, saldos y movimientos
        Route::get('/cash', [\App\Http\Controllers\FinanceCashController::class, 'index']);
        Route::get('/cash/dashboard', [\App\Http\Controllers\FinanceCashController::class, 'dashboard']); // §46, §48
        // Repartidores propios (R11): monto fijo o tarifario por distancia
        Route::get('/deliverers', [\App\Http\Controllers\FinanceDeliverersController::class, 'index']);
        Route::put('/deliverers/{user}', [\App\Http\Controllers\FinanceDeliverersController::class, 'update']);
        // Reportes (entrega 4; §53 a §58, §80, R18)
        Route::get('/reports', [\App\Http\Controllers\FinanceReportsController::class, 'index']);
        Route::post('/reports', [\App\Http\Controllers\FinanceReportsController::class, 'store']);
        Route::get('/reports-config', [\App\Http\Controllers\FinanceReportsController::class, 'config']);
        Route::put('/reports-config', [\App\Http\Controllers\FinanceReportsController::class, 'updateConfig']);
        Route::get('/export/excel', [\App\Http\Controllers\FinanceReportsController::class, 'export']);
        Route::get('/reports/{report}', [\App\Http\Controllers\FinanceReportsController::class, 'show']);
        Route::post('/reports/{report}/refresh', [\App\Http\Controllers\FinanceReportsController::class, 'refresh']);
        Route::get('/reports/{report}/pdf', [\App\Http\Controllers\FinanceReportsController::class, 'pdf']);
        Route::get('/reports/{report}/excel', [\App\Http\Controllers\FinanceReportsController::class, 'excel']);
        Route::post('/reports/{report}/whatsapp', [\App\Http\Controllers\FinanceReportsController::class, 'whatsapp']);
        Route::get('/cash/transactions', [\App\Http\Controllers\FinanceCashController::class, 'transactions']);
        Route::post('/cash/accounts', [\App\Http\Controllers\FinanceCashController::class, 'storeAccount']);
        Route::put('/cash/accounts/{account}', [\App\Http\Controllers\FinanceCashController::class, 'updateAccount']);
        Route::put('/cash/opening-balances', [\App\Http\Controllers\FinanceCashController::class, 'openingBalances']);
        Route::put('/cash/company-accounts/{companyAccount}', [\App\Http\Controllers\FinanceCashController::class, 'linkCompanyAccount']);
        // Movimientos a mano (entrega 3b; §37 a §41, §79, §83)
        Route::post('/cash/movements', [\App\Http\Controllers\FinanceCashController::class, 'storeMovement']);
        Route::post('/cash/movements/{movement}/void', [\App\Http\Controllers\FinanceCashController::class, 'voidMovement']);
        Route::get('/cash/movements/{movement}/receipt', [\App\Http\Controllers\FinanceCashController::class, 'movementReceipt']);
        // Agencias (entrega 3c; §43, §101 a §104, R16)
        Route::get('/agencies', [\App\Http\Controllers\FinanceCashController::class, 'agencies']);
        Route::post('/agencies/{agency}/settle', [\App\Http\Controllers\FinanceCashController::class, 'settle']);
        // Los demás pagos (entrega 3d; §28, §44, §45, §77)
        Route::get('/cash/payables', [\App\Http\Controllers\FinanceCashController::class, 'payables']);
        Route::get('/receivables', [\App\Http\Controllers\FinanceCashController::class, 'receivables']);
        Route::post('/receivables', [\App\Http\Controllers\FinanceCashController::class, 'storeReceivable']);
        Route::post('/receivables/{receivable}/collections', [\App\Http\Controllers\FinanceCashController::class, 'collect']);
        Route::post('/receivables/{receivable}/void', [\App\Http\Controllers\FinanceCashController::class, 'voidReceivable']);
        Route::post('/receivable-collections/{collection}/void', [\App\Http\Controllers\FinanceCashController::class, 'voidCollection']);
        Route::put('/refunds/{refund}/account', [\App\Http\Controllers\FinanceCashController::class, 'refundAccount']);
        Route::post('/recurring-expenses/{recurring}/payments', [\App\Http\Controllers\FinanceExpenseController::class, 'recurringPay']);
        Route::post('/recurring-payments/{payment}/void', [\App\Http\Controllers\FinanceExpenseController::class, 'recurringPaymentVoid']);
    });

    // "Cierres / Servicios" de la agencia (§22, §23, §38): sus semanas y subir su archivo, sin nada interno
    Route::middleware('role:Agencia')->group(function () {
        Route::get('/my-closures', [\App\Http\Controllers\MyClosureController::class, 'index']);
        Route::post('/my-closures/{closure}/upload', [\App\Http\Controllers\MyClosureController::class, 'upload']);
    });

    // TEST NOTIFICATIONS (solo notifica al propio usuario)
    Route::post('/test/notifications', [\App\Http\Controllers\TestNotificationController::class, 'trigger']);

    Route::apiResource('statuses', \App\Http\Controllers\StatusController::class)->only(['index', 'show']);
    Route::apiResource('statuses', \App\Http\Controllers\StatusController::class)->except(['index', 'show'])->middleware('role:Admin,Master');

    /**---------------------
     * WEBHOOKS (OUTGOING) — solo Admin: pueden enviar datos de clientes a cualquier URL
     * ---------------------**/
    Route::apiResource('webhooks', \App\Http\Controllers\WebhookController::class)->middleware('role:Admin,Master');

    /**---------------------
     * LOGÍSTICA PROPIA (documento de Fran del 2026-10-21 y sus respuestas R1–R8). La configuración de los centros es
     * del Admin (§23); el turno, las órdenes y las alertas, del operador de su centro (§3, §4.2, §21).
     * ---------------------**/
    Route::middleware('role:Admin,Master')->prefix('logistics')->group(function () {
        Route::get('/centers', [\App\Http\Controllers\LogisticsCenterController::class, 'index']);
        Route::post('/centers', [\App\Http\Controllers\LogisticsCenterController::class, 'store']);
        Route::put('/centers/{center}', [\App\Http\Controllers\LogisticsCenterController::class, 'update']);
        Route::put('/centers/{center}/active', [\App\Http\Controllers\LogisticsCenterController::class, 'setActive']);
        Route::put('/centers/{center}/operators', [\App\Http\Controllers\LogisticsCenterController::class, 'syncOperators']);
        Route::post('/centers/{center}/exceptions', [\App\Http\Controllers\LogisticsCenterController::class, 'storeException']);
        Route::delete('/centers/{center}/exceptions/{exception}', [\App\Http\Controllers\LogisticsCenterController::class, 'destroyException']);
        Route::get('/centers/{center}/calendar-setup', [\App\Http\Controllers\LogisticsCenterController::class, 'calendarSetup']);
        Route::post('/centers/{center}/patterns', [\App\Http\Controllers\LogisticsCenterController::class, 'storePattern']);
        Route::put('/centers/{center}/patterns/{pattern}/disable', [\App\Http\Controllers\LogisticsCenterController::class, 'disablePattern']);
        Route::post('/centers/{center}/overrides', [\App\Http\Controllers\LogisticsCenterController::class, 'storeOverride']);
        Route::delete('/centers/{center}/overrides/{override}', [\App\Http\Controllers\LogisticsCenterController::class, 'destroyOverride']);
        Route::get('/settings', [\App\Http\Controllers\LogisticsCenterController::class, 'settings']);
        Route::put('/settings', [\App\Http\Controllers\LogisticsCenterController::class, 'updateSettings']);
        Route::get('/riders', [\App\Http\Controllers\LogisticsRiderController::class, 'index']);
        Route::put('/riders/{user}', [\App\Http\Controllers\LogisticsRiderController::class, 'update']);
        Route::post('/riders/{user}/test-push', [\App\Http\Controllers\LogisticsRiderController::class, 'testPush']);
    });
    Route::middleware('role:Admin,Master,Operador logístico')->prefix('logistics')->group(function () {
        Route::get('/my-centers', [\App\Http\Controllers\LogisticsShiftController::class, 'mine']);
        Route::get('/centers/{center}/board', [\App\Http\Controllers\LogisticsShiftController::class, 'show']);
        Route::get('/centers/{center}/orders', [\App\Http\Controllers\LogisticsShiftController::class, 'orders']);
        Route::post('/centers/{center}/shift/start', [\App\Http\Controllers\LogisticsShiftController::class, 'start']);
        Route::get('/centers/{center}/shift/pending', [\App\Http\Controllers\LogisticsShiftController::class, 'pending']);
        Route::post('/centers/{center}/shift/close', [\App\Http\Controllers\LogisticsShiftController::class, 'close']);
        Route::post('/centers/{center}/alerts/{alert}/resolve', [\App\Http\Controllers\LogisticsShiftController::class, 'resolveAlert']);
        Route::post('/road-reports/{report}/vote', [\App\Http\Controllers\LogisticsShiftController::class, 'voteRoadReport']);
        Route::get('/centers/{center}/calendar', [\App\Http\Controllers\LogisticsShiftController::class, 'calendar']);
        Route::get('/centers/{center}/riders', [\App\Http\Controllers\LogisticsRiderController::class, 'forCenter']);
        Route::post('/centers/{center}/riders/{user}/cash', [\App\Http\Controllers\LogisticsRiderController::class, 'receiveCash']);
        Route::get('/centers/{center}/payouts', [\App\Http\Controllers\LogisticsRiderController::class, 'payouts']);
        Route::post('/centers/{center}/riders/{user}/payout', [\App\Http\Controllers\LogisticsRiderController::class, 'payRider']);
        Route::get('/orders/{logisticsOrder}/candidates', [\App\Http\Controllers\LogisticsRiderController::class, 'candidates']);
        Route::get('/orders/{logisticsOrder}', [\App\Http\Controllers\LogisticsDispatchController::class, 'show']);
        Route::post('/orders/{logisticsOrder}/assign', [\App\Http\Controllers\LogisticsDispatchController::class, 'assign']);
        Route::post('/orders/{logisticsOrder}/to-agency', [\App\Http\Controllers\LogisticsDispatchController::class, 'toAgency']);
        Route::post('/orders/{logisticsOrder}/prepare', [\App\Http\Controllers\LogisticsDispatchController::class, 'prepare']);
        Route::post('/orders/{logisticsOrder}/ready', [\App\Http\Controllers\LogisticsDispatchController::class, 'ready']);
        Route::post('/orders/{logisticsOrder}/depart', [\App\Http\Controllers\LogisticsDispatchController::class, 'depart']);
        Route::post('/orders/{logisticsOrder}/hand-to-agency', [\App\Http\Controllers\LogisticsDispatchController::class, 'handToAgency']);
        Route::post('/orders/{logisticsOrder}/receive-return', [\App\Http\Controllers\LogisticsDispatchController::class, 'receiveReturn']);
        Route::post('/orders/{logisticsOrder}/review', [\App\Http\Controllers\LogisticsDispatchController::class, 'resolveReturn']);
        Route::post('/orders/{logisticsOrder}/transfer', [\App\Http\Controllers\LogisticsDispatchController::class, 'transfer']);
        Route::post('/incidents/{incident}/continue', [\App\Http\Controllers\LogisticsDispatchController::class, 'incidentContinue']);
        Route::post('/incidents/{incident}/interrupt', [\App\Http\Controllers\LogisticsDispatchController::class, 'incidentInterrupt']);
        Route::post('/novelties/{novelty}/comment', [\App\Http\Controllers\LogisticsDispatchController::class, 'commentNovelty']);
        Route::post('/novelties/{novelty}/resolve', [\App\Http\Controllers\LogisticsDispatchController::class, 'resolveNovelty']);
        Route::post('/novelties/{novelty}/escalate', [\App\Http\Controllers\LogisticsDispatchController::class, 'escalateNovelty']);
    });
    // La app del rider (logística propia, §22): solo lo suyo
    Route::middleware('role:Repartidor')->prefix('rider')->group(function () {
        Route::get('/me', [\App\Http\Controllers\RiderAppController::class, 'me']);
        Route::post('/device', [\App\Http\Controllers\RiderAppController::class, 'device']);
        Route::post('/locations', [\App\Http\Controllers\RiderAppController::class, 'locations']);
        Route::get('/offers', [\App\Http\Controllers\RiderAppController::class, 'offers']);
        Route::post('/offers/{offer}/seen', [\App\Http\Controllers\RiderAppController::class, 'seen']);
        Route::post('/offers/{offer}/confirm', [\App\Http\Controllers\RiderAppController::class, 'confirm']);
        Route::get('/assignment', [\App\Http\Controllers\RiderAppController::class, 'assignment']);
        Route::get('/novelty-types', [\App\Http\Controllers\RiderAppController::class, 'noveltyTypes']);
        Route::post('/assignment/{logisticsOrder}/novelty', [\App\Http\Controllers\RiderAppController::class, 'novelty']);
        Route::get('/incident-types', [\App\Http\Controllers\RiderAppController::class, 'incidentTypes']);
        Route::get('/road-reports', [\App\Http\Controllers\RiderAppController::class, 'roadReports']);
        Route::post('/road-reports', [\App\Http\Controllers\RiderAppController::class, 'roadReport']);
        Route::post('/road-reports/{report}/vote', [\App\Http\Controllers\RiderAppController::class, 'roadReportVote']);
        Route::post('/assignment/{logisticsOrder}/incident', [\App\Http\Controllers\RiderAppController::class, 'incident']);
        Route::post('/assignment/{logisticsOrder}/transfer', [\App\Http\Controllers\RiderAppController::class, 'transfer']);
    });
});

