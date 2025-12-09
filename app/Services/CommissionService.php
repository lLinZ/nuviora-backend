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
        $already = Earning::where('order_id', $order->id)->exists();
        if ($already) {
            return;
        }

        $rate = 1;
        $currency = 'USD';
        $earningDate = now()->toDateString(); // o $order->processed_at? o delivered_at si lo tienes

        DB::transaction(function () use ($order, $rate, $currency, $earningDate) {

            // 1 USD por orden completada → VENDEDORA
            if ($order->agent_id) {
                Earning::create([
                    'order_id'     => $order->id,
                    'user_id'      => $order->agent_id,
                    'role_type'    => 'vendedor',
                    'amount_usd'   => 1.00,
                    'currency'     => $currency,
                    'rate'         => $rate,
                    'earning_date' => $earningDate,
                ]);
            }

            // 2.5 USD por orden entregada → REPARTIDOR
            if ($order->deliverer_id) {
                Earning::create([
                    'order_id'     => $order->id,
                    'user_id'      => $order->deliverer_id,
                    'role_type'    => 'repartidor',
                    'amount_usd'   => 2.50,
                    'currency'     => $currency,
                    'rate'         => $rate,
                    'earning_date' => $earningDate,
                ]);
            }

            // 0.5 USD por venta exitosa → GERENTE
            $manager = User::whereHas('role', function ($q) {
                $q->where('description', 'Gerente');
            })->first();

            if ($manager) {
                Earning::create([
                    'order_id'     => $order->id,
                    'user_id'      => $manager->id,
                    'role_type'    => 'gerente',
                    'amount_usd'   => 0.50,
                    'currency'     => $currency,
                    'rate'         => $rate,
                    'earning_date' => $earningDate,
                ]);
            }
        });
    }
}
