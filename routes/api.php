<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\FacebookEventController;
use App\Http\Controllers\OrderCancellationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderDelivererController;
use App\Http\Controllers\OrderPostponementController;
use App\Http\Controllers\OrderUpdateController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

// Crear Admin
Route::post('register/master/24548539', [AuthController::class, 'register_master']);

// Login
Route::post('login', [AuthController::class, 'login']);

Route::post('order/webhook', [ShopifyWebhookController::class, 'handleOrderCreate']);
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

    Route::get('/users/deliverers', [AuthController::class, 'deliverers']);
    Route::post('/users/deliverers', [AuthController::class, 'storeDeliverer']); // crear repartidor
    Route::put('/orders/{order}/assign-deliverer', [OrderDelivererController::class, 'assign']);
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
    Route::post('currency', [CurrencyController::class, 'create']);
    // Obtener la ultima divisa
    Route::get('currency', [CurrencyController::class, 'get_last_currency']);

    /*---------------------
     * CANCELACIONES DE ORDEN
     * ---------------------**/
    // Aprobar / Rechazar
    Route::patch('cancellations/{cancellation}/review', [OrderCancellationController::class, 'review']);
    // Listar cancelaciones
    Route::get('cancellations', [OrderCancellationController::class, 'index']);

    Route::post('facebook/events', [FacebookEventController::class, 'sendEvent']);
    Route::post('shopify/orders/create', [ShopifyWebhookController::class, 'orderCreated']);
});
