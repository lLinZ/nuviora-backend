<?php

namespace App\Services\Logistics;

use App\Models\LogisticsAlert;
use App\Models\LogisticsCenter;
use App\Models\LogisticsOrder;
use App\Models\Order;
use App\Models\RiderCashMovement;
use App\Models\RiderProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\EarningsService;
use Illuminate\Support\Facades\DB;

/**
 * El efectivo bajo custodia de cada rider (§17; R6). Cada entrega en efectivo suma a su saldo (cash_custody_balance) y
 * cada entrega al operador resta lo que se recibió:
 * - el saldo se lleva en dólares y en bolívares, y en USD con los bolívares a la tasa de su orden (R6: "los bolívares
 *   cuentan para los USD 60"); el monto de cada cobro sale del mismo cálculo que usa Finanzas (EarningsService::orderCash);
 * - al llegar a USD 60 (configurable, §31) hay alerta persistente al operador (§25) y el rider no sale con otra ruta sin
 *   entregarlo (§6.1: "exigir conciliación/entrega física antes de entregarle la nueva ruta");
 * - el operador registra lo esperado contra lo recibido; si no cuadra, queda una incidencia de caja para administración
 *   y la deuda no se borra: lo que falta sigue en el saldo (§17 puntos 4 y 5).
 * El efectivo COD y el pago del rider son balances separados (§18): aquí solo está el COD.
 */
class RiderCash
{
    public function __construct(private Alerts $alerts, private LogisticsSettings $settings)
    {
    }

    /** §17: "Cada entrega en efectivo incrementa automáticamente cash_custody_balance del rider". Una vez por orden. */
    public function recordDelivery(LogisticsOrder $row, Order $order, ?int $actorId = null): ?RiderCashMovement
    {
        if (!$row->rider_id || RiderCashMovement::where('logistics_order_id', $row->id)->where('type', RiderCashMovement::COLLECTION)->exists()) {
            return null;
        }
        $order->loadMissing('payments');
        $cash = EarningsService::orderCash($order, self::binanceRate(), (float) (Setting::get('rate_bcv_eur', 1) ?: 1));
        $usd = round($cash['net_usd'], 2);
        $ves = round($cash['net_ves'], 2);
        if (abs($usd) < 0.005 && abs($ves) < 0.005) {
            return null; // pago digital: el rider no se queda con efectivo
        }
        $movement = RiderCashMovement::create([
            'rider_id' => $row->rider_id,
            'center_id' => $row->center_id,
            'type' => RiderCashMovement::COLLECTION,
            'logistics_order_id' => $row->id,
            'order_id' => $order->id,
            'usd' => $usd,
            'ves' => $ves,
            'total_usd' => round($cash['net_usd'] + $cash['collected_ves_usd'] - $cash['change_ves_usd'], 2),
            'actor_id' => $actorId,
            'note' => "Cobro de la orden {$order->name}",
            'created_at' => now(),
        ]);
        if ($row->center) {
            $this->checkLimit($row->rider_id, $row->center);
        }

        return $movement;
    }

    /** §17 puntos 4 y 5: el operador recibe el efectivo y registra lo esperado contra lo recibido. */
    public function receive(LogisticsCenter $center, int $riderId, User $by, float $usd, float $ves, ?string $note = null): array
    {
        if ($usd < 0 || $ves < 0 || ($usd < 0.005 && $ves < 0.005)) {
            return ['ok' => false, 'message' => 'Escribe cuánto entregó el rider, en dólares o en bolívares.', 'code' => 422];
        }
        $out = DB::transaction(function () use ($center, $riderId, $by, $usd, $ves, $note) {
            RiderProfile::where('user_id', $riderId)->lockForUpdate()->first();
            $expected = $this->balance($riderId);
            // Los bolívares que entrega salen a la tasa a la que entraron (el promedio de lo que tiene)
            $usdPerBs = $expected['ves'] > 0.005 ? ($expected['total_usd'] - $expected['usd']) / $expected['ves'] : 1 / self::binanceRate();
            $diffUsd = round($expected['usd'] - $usd, 2);
            $diffVes = round($expected['ves'] - $ves, 2);
            $hasDiff = abs($diffUsd) >= 0.01 || abs($diffVes) >= 0.01;
            $movement = RiderCashMovement::create([
                'rider_id' => $riderId,
                'center_id' => $center->id,
                'type' => RiderCashMovement::HANDOVER,
                'usd' => -round($usd, 2),
                'ves' => -round($ves, 2),
                'total_usd' => -round($usd + $ves * $usdPerBs, 2),
                'expected_usd' => $expected['usd'],
                'expected_ves' => $expected['ves'],
                'received_usd' => round($usd, 2),
                'received_ves' => round($ves, 2),
                'has_difference' => $hasDiff,
                'actor_id' => $by->id,
                'note' => $note,
                'created_at' => now(),
            ]);

            return [$movement, $expected, $hasDiff, $diffUsd, $diffVes];
        });
        [$movement, $expected, $hasDiff, $diffUsd, $diffVes] = $out;
        $name = User::whereKey($riderId)->value('names') ?? "Rider #{$riderId}";
        if ($hasDiff) {
            // §25: "Administrador | Diferencia de caja | Alerta/pendiente"
            $this->alerts->raise($center, 'caja_diferencia', LogisticsAlert::RED,
                "Diferencia de caja: {$name} debía " . self::money($expected['usd'], $expected['ves']) . ' y entregó ' . self::money($usd, $ves)
                . '. La diferencia sigue en su saldo (§17).', "caja:{$movement->id}", null, $riderId);
        }
        $this->checkLimit($riderId, $center);
        $left = $this->balance($riderId);

        return [
            'ok' => true,
            'message' => $hasDiff
                ? 'Recibido con diferencia (' . self::money($diffUsd, $diffVes) . '): queda una incidencia de caja y la diferencia sigue en el saldo del rider.'
                : 'Efectivo recibido: cuadra con lo esperado.',
            'code' => 200,
            'balance' => $left,
        ];
    }

    /** @return array{usd: float, ves: float, total_usd: float} */
    public function balance(int $riderId): array
    {
        return $this->balances([$riderId])[$riderId] ?? ['usd' => 0.0, 'ves' => 0.0, 'total_usd' => 0.0];
    }

    /** @return array<int, array{usd: float, ves: float, total_usd: float}> */
    public function balances(iterable $riderIds): array
    {
        $ids = collect($riderIds)->map(fn ($id) => (int) $id)->unique()->values();
        $rows = RiderCashMovement::whereIn('rider_id', $ids)->groupBy('rider_id')
            ->selectRaw('rider_id, SUM(usd) AS usd, SUM(ves) AS ves, SUM(total_usd) AS total_usd')->get()->keyBy('rider_id');

        return $ids->mapWithKeys(fn ($id) => [$id => [
            'usd' => round((float) ($rows[$id]->usd ?? 0), 2),
            'ves' => round((float) ($rows[$id]->ves ?? 0), 2),
            'total_usd' => round((float) ($rows[$id]->total_usd ?? 0), 2),
        ]])->all();
    }

    public function limit(LogisticsCenter $center): float
    {
        return (float) $this->settings->for($center)['cash_limit_usd'];
    }

    public function overLimit(int $riderId, LogisticsCenter $center): bool
    {
        $limit = $this->limit($center);

        return $limit > 0 && $this->balance($riderId)['total_usd'] >= $limit;
    }

    /** §17 punto 2 y §25 ("Rider ≥ USD 60 — Alerta persistente"): la alerta sigue abierta mientras no entregue. */
    public function checkLimit(int $riderId, LogisticsCenter $center): void
    {
        $key = "efectivo:{$riderId}:limite";
        if (!$this->overLimit($riderId, $center)) {
            $this->alerts->resolve($key);

            return;
        }
        $name = User::whereKey($riderId)->value('names') ?? "Rider #{$riderId}";
        $this->alerts->raise($center, 'efectivo_limite', LogisticsAlert::RED,
            "{$name} lleva USD " . number_format($this->balance($riderId)['total_usd'], 2, ',', '.') . ' en efectivo (el tope es USD '
            . number_format($this->limit($center), 0) . '): tiene que volver al centro a entregarlo antes de seguir cobrando (§17).', $key, null, $riderId);
    }

    /** La corre logistics:tick: si alguien cerró la alerta sin que el rider entregara, vuelve (es persistente). */
    public function checkCenter(LogisticsCenter $center): void
    {
        $riderIds = RiderProfile::where('center_id', $center->id)->pluck('user_id');
        foreach ($this->balances($riderIds) as $riderId => $balance) {
            if ($balance['total_usd'] >= $this->limit($center) && $this->limit($center) > 0) {
                $this->checkLimit($riderId, $center);
            }
        }
    }

    public static function money(float $usd, float $ves): string
    {
        $parts = [];
        if (abs($usd) >= 0.005) {
            $parts[] = 'USD ' . number_format($usd, 2, ',', '.');
        }
        if (abs($ves) >= 0.005) {
            $parts[] = 'Bs ' . number_format($ves, 2, ',', '.');
        }

        return $parts ? implode(' y ', $parts) : 'USD 0.00';
    }

    private static function binanceRate(): float
    {
        return max(0.0001, (float) (Setting::get('rate_binance_usd', 1) ?: 1));
    }
}
