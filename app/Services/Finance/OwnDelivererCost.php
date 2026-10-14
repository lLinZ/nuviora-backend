<?php

namespace App\Services\Finance;

use App\Models\DelivererCostSetting;
use App\Models\FinancialAuditLog;
use App\Models\Order;
use App\Services\CommissionService;
use App\Services\Delivery\GoogleRoutesClient;
use App\Services\Delivery\MapsLinkResolver;
use App\Services\Delivery\TariffBook;

/**
 * Lo que cobra un repartidor propio por una entrega (Módulo 4 de Fran, R11):
 * - "monto fijo": el suyo o, si no tiene, el monto general (finance.commission_deliverer, $2,50);
 * - "tarifario": la distancia por carretera desde el almacén que eligió el Admin hasta la ubicación del cliente, con el
 *   tarifario del repartidor para esa ciudad (el mismo del Módulo 3, cargado a su nombre en Tarifas de delivery).
 * Si el tarifario no se puede aplicar (sin almacén con coordenadas, sin ubicación, sin tarifario o Google no responde),
 * se paga el monto fijo y queda dicho por qué en la auditoría (§82). Cada cálculo queda ahí con su base.
 */
class OwnDelivererCost
{
    public function __construct(private TariffBook $tariffs, private GoogleRoutesClient $google)
    {
    }

    /** @return array{amount: float, basis: array} */
    public function forOrder(Order $order): array
    {
        $setting = DelivererCostSetting::with('warehouse')->where('user_id', $order->deliverer_id)->first();
        $fixed = $setting?->fixed_amount ?? CommissionService::amounts()['deliverer'];
        if (!$setting || $setting->mode !== DelivererCostSetting::TARIFF) {
            return ['amount' => round($fixed, 2), 'basis' => ['mode' => 'fixed', 'amount' => round($fixed, 2), 'general' => $setting?->fixed_amount === null]];
        }

        $fallback = fn (string $why) => ['amount' => round($fixed, 2), 'basis' => ['mode' => 'tariff', 'fallback' => $why, 'amount' => round($fixed, 2)]];
        $warehouse = $setting->warehouse;
        if (!$warehouse || !$warehouse->hasCoordinates()) {
            return $fallback('El almacén de salida no está elegido o no tiene coordenadas.');
        }
        if ($order->location_status !== MapsLinkResolver::OK || $order->location_lat === null) {
            return $fallback('La orden no tiene una ubicación legible.');
        }
        $cityId = $order->city_id ?? $warehouse->city_id;
        $tariff = $cityId ? $this->tariffs->find((int) $order->deliverer_id, (int) $cityId, now(config('app.timezone'))->toDateString()) : null;
        if (!$tariff) {
            return $fallback('El repartidor no tiene tarifario vigente en esa ciudad.');
        }
        try {
            $meters = $this->google->distanceMeters((float) $warehouse->latitude, (float) $warehouse->longitude, (float) $order->location_lat, (float) $order->location_lng);
        } catch (\Throwable $e) {
            return $fallback('Google Routes no respondió: ' . mb_substr($e->getMessage(), 0, 120));
        }
        $quote = $this->tariffs->quote($tariff, $meters);
        if (!$quote) {
            return $fallback('La distancia (' . round($meters / 1000, 1) . ' km) está fuera de los rangos de su tarifario.');
        }

        return ['amount' => round((float) $quote['price'], 2), 'basis' => [
            'mode' => 'tariff', 'warehouse_id' => $warehouse->id, 'distance_km' => round($meters / 1000, 3), 'tariff_id' => $tariff->id,
            'tariff_version' => $tariff->version, 'range_id' => $quote['range']->id, 'amount' => round((float) $quote['price'], 2),
        ]];
    }

    /** El cálculo con su base, en la auditoría (§82). */
    public static function record(Order $order, array $result): void
    {
        FinancialAuditLog::record('deliverer_cost_calculated', 'order', $order->id, null, $result['basis'] + ['deliverer_id' => $order->deliverer_id],
            $result['basis']['fallback'] ?? null);
    }
}
