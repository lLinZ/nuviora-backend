<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Earning;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class CommissionService
{
    /**
     * Montos configurables por administración (Módulo 4 de Fran, §22). Por defecto, lo de siempre: $1 por pedido
     * entregado, $1 por cada unidad de upsell entregada (R10: "si son dos unidades de upsell, serían dos dólares") y
     * $2,50 al repartidor propio.
     */
    public const SETTING_ORDER = 'finance.commission_order';
    public const SETTING_UPSELL_UNIT = 'finance.commission_upsell_unit';
    public const SETTING_DELIVERER = 'finance.commission_deliverer';

    public static function amounts(): array
    {
        return [
            'order' => (float) Setting::get(self::SETTING_ORDER, 1.00),
            'upsell_unit' => (float) Setting::get(self::SETTING_UPSELL_UNIT, 1.00),
            'deliverer' => (float) Setting::get(self::SETTING_DELIVERER, 2.50),
        ];
    }

    /**
     * Genera las ganancias para una orden cuando se marca como ENTREGADA. Es el único momento en que nacen: Fran,
     * Módulo 4, §22 y R9 (2026-10-12), "solamente y exclusivamente… cuando la orden esté entregada, no cuando esté
     * confirmada".
     */
    public function generateForDeliveredOrder(Order $order): void
    {
        // Skip commission generation for return orders
        if ($order->is_return) {
            return;
        }

        $rate = 1;
        $currency = 'USD';
        $earningDate = now()->toDateString();
        $amounts = self::amounts();
        // R11 (Módulo 4): el repartidor propio cobra su monto fijo o su tarifario por distancia. Se calcula una sola vez,
        // fuera de la transacción (puede consultar Google Routes), y queda en la auditoría con su base.
        $delivererCost = null;
        if ($order->deliverer_id && !Earning::where('order_id', $order->id)->where('role_type', 'repartidor')->exists()) {
            $delivererCost = app(\App\Services\Finance\OwnDelivererCost::class)->forOrder($order);
            $amounts['deliverer'] = $delivererCost['amount'];
        }

        DB::transaction(function () use ($order, $rate, $currency, $earningDate, $amounts, $delivererCost) {

            // 1. VENDEDORA
            if ($order->agent_id) {
                Earning::firstOrCreate([
                    'order_id'  => $order->id,
                    'user_id'   => $order->agent_id,
                    'role_type' => 'vendedor',
                ], [
                    'amount_usd'   => $amounts['order'],
                    'currency'     => $currency,
                    'rate'         => $rate,
                    'earning_date' => $earningDate,
                ]);
            }

            // 2. REPARTIDOR
            if ($order->deliverer_id) {
                $earning = Earning::firstOrCreate([
                    'order_id'  => $order->id,
                    'user_id'   => $order->deliverer_id,
                    'role_type' => 'repartidor',
                ], [
                    'amount_usd'   => $amounts['deliverer'],
                    'currency'     => $currency,
                    'rate'         => $rate,
                    'earning_date' => $earningDate,
                ]);
                if ($delivererCost && $earning->wasRecentlyCreated) {
                    \App\Services\Finance\OwnDelivererCost::record($order, $delivererCost);
                }
            }

            // 3. GERENTE: ya no hay (Fran, 2026-10-02, se quitó el rol y su comisión de 0,50 USD por orden).
            //    Lo que ya cobró queda en el historial de ganancias.

            // 4. UPSELLS (por cada unidad de producto adicional)
            // Borramos los previos para sincronizar si se agregaron/quitaron items
            Earning::where('order_id', $order->id)->where('role_type', 'upsell')->delete();

            $upsells = $order->products()->where('is_upsell', true)->get();
            foreach ($upsells as $upsell) {
                if ($upsell->upsell_user_id) {
                    Earning::create([
                        'order_id'     => $order->id,
                        'user_id'      => $upsell->upsell_user_id,
                        'role_type'    => 'upsell',
                        'amount_usd'   => $amounts['upsell_unit'] * $upsell->quantity,
                        'currency'     => $currency,
                        'rate'         => $rate,
                        'earning_date' => $earningDate,
                    ]);
                }
            }

        });
    }
}
