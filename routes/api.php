<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StatusController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('register/master/24548539', [AuthController::class, 'register_master']);
// Login
Route::post('login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    /**---------------------
     * USERS
     * ---------------------**/
    // Validacion de token
    Route::get('user/data', [AuthController::class, 'get_logged_user_data']);
    // Registrar usuario
    Route::put('user/{user}/change/color', [AuthController::class, 'edit_color']);
    Route::put('user/{user}/change/theme', [AuthController::class, 'edit_theme']);
    Route::put('user/{user}/change/password', [AuthController::class, 'edit_password']);
    // Cerrar sesion
    Route::get('logout', [AuthController::class, 'logout']);

    Route::post('status', [StatusController::class, 'create']);
    Route::post('role', [RoleController::class, 'create']);
});
