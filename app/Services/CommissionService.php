<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\Earning;
use Illuminate\Support\Facades\DB;

class CommissionService
{
    /**
     * Genera las ganancias para una orden cuando se marca como ENTREGADA.
     */
    public function generateForDeliveredOrder(Order $order): void
    {
        $rate = 1;
        $currency = 'USD';
        $earningDate = now()->toDateString(); 

        DB::transaction(function () use ($order, $rate, $currency, $earningDate) {

            // 1. VENDEDORA (1 USD)
            if ($order->agent_id) {
                Earning::firstOrCreate([
                    'order_id'  => $order->id,
                    'user_id'   => $order->agent_id,
                    'role_type' => 'vendedor',
                ], [
                    'amount_usd'   => 1.00,
                    'currency'     => $currency,
                    'rate'         => $rate,
                    'earning_date' => $earningDate,
                ]);
            }

            // 2. REPARTIDOR (2.50 USD)
            if ($order->deliverer_id) {
                Earning::firstOrCreate([
                    'order_id'  => $order->id,
                    'user_id'   => $order->deliverer_id,
                    'role_type' => 'repartidor',
                ], [
                    'amount_usd'   => 2.50,
                    'currency'     => $currency,
                    'rate'         => $rate,
                    'earning_date' => $earningDate,
                ]);
            }

            // 3. GERENTE (0.50 USD)
            $manager = User::whereHas('role', function ($q) {
                $q->where('description', '=', 'Gerente');
            })->first();

            if ($manager) {
                Earning::firstOrCreate([
                    'order_id'  => $order->id,
                    'user_id'   => $manager->id,
                    'role_type' => 'gerente',
                ], [
                    'amount_usd'   => 0.50,
                    'currency'     => $currency,
                    'rate'         => $rate,
                    'earning_date' => $earningDate,
                ]);
            }

            // 4. UPSELLS ($1.00 por cada PRODUCTO adicional)
            // Borramos los previos para sincronizar si se agregaron/quitaron items
            Earning::where('order_id', $order->id)->where('role_type', 'upsell')->delete();

            $upsells = $order->products()->where('is_upsell', true)->get();
            foreach ($upsells as $upsell) {
                if ($upsell->upsell_user_id) {
                    Earning::create([
                        'order_id'     => $order->id,
                        'user_id'      => $upsell->upsell_user_id,
                        'role_type'    => 'upsell',
                        'amount_usd'   => 1.00 * $upsell->quantity,
                        'currency'     => $currency,
                        'rate'         => $rate,
                        'earning_date' => $earningDate,
                    ]);
                }
            }

        });
    }

    /**
     * Genera las ganancias para una orden cuando se marca como CONFIRMADO.
     * Solo para Vendedor y Upsells.
     */
    public function generateForConfirmedOrder(Order $order): void
    {
        $currency = 'USD';
        $rate = 1;
        $earningDate = now()->toDateString();

        DB::transaction(function () use ($order, $rate, $currency, $earningDate) {
            // 1. VENDEDORA (1 USD)
            if ($order->agent_id) {
                Earning::firstOrCreate([
                    'order_id'  => $order->id,
                    'user_id'   => $order->agent_id,
                    'role_type' => 'vendedor',
                ], [
                    'amount_usd'   => 1.00,
                    'currency'     => $currency,
                    'rate'         => $rate,
                    'earning_date' => $earningDate,
                ]);
            }

            // 2. UPSELLS ($1.00 por cada PRODUCTO adicional)
            Earning::where('order_id', $order->id)->where('role_type', 'upsell')->delete();

            $upsells = $order->products()->where('is_upsell', true)->get();
            foreach ($upsells as $upsell) {
                if ($upsell->upsell_user_id) {
                    Earning::create([
                        'order_id'     => $order->id,
                        'user_id'      => $upsell->upsell_user_id,
                        'role_type'    => 'upsell',
                        'amount_usd'   => 1.00 * $upsell->quantity,
                        'currency'     => $currency,
                        'rate'         => $rate,
                        'earning_date' => $earningDate,
                    ]);
                }
            }
        });
    }
}
