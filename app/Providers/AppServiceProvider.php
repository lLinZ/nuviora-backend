<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Order::observe(\App\Observers\OrderTrackingObserver::class);
        \App\Models\Order::observe(\App\Observers\OrderLogisticsObserver::class);
        // Módulo 3 de Fran: almacén de la orden, ubicación del cliente y ruta con su costo esperado
        \App\Models\Order::observe(\App\Observers\OrderDeliveryObserver::class);
    }
}
