<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\StatusController;
use Illuminate\Http\Request;
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
    // Validacion de token
    Route::get('users', [AuthController::class, 'get_all_users']);
    Route::put('user/{user}', [AuthController::class, 'edit_user_data']);
    Route::get('user/data', [AuthController::class, 'get_logged_user_data']);
    // Registrar usuario
    Route::put('user/{user}/change/color', [AuthController::class, 'edit_color']);
    Route::put('user/{user}/change/theme', [AuthController::class, 'edit_theme']);
    Route::put('user/{user}/change/password', [AuthController::class, 'edit_password']);
    // Cerrar sesion
    Route::get('logout', [AuthController::class, 'logout']);

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


    /**---------------------
     * CURRENCY
     * ---------------------**/
    // Crear divisa
    Route::post('currency', [CurrencyController::class, 'create']);
    // Obtener la ultima divisa
    Route::get('currency', [CurrencyController::class, 'get_last_currency']);
});
