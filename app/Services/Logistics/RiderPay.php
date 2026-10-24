<?php

namespace App\Services\Logistics;

use App\Models\Earning;
use App\Models\LogisticsCenter;
use App\Models\LogisticsIncident;
use App\Models\LogisticsNovelty;
use App\Models\LogisticsOrder;
use App\Models\Order;
use App\Models\RiderPayout;
use App\Models\RiderProfile;
use App\Models\RiderTrip;
use App\Models\User;
use App\Services\Delivery\TariffBook;
use Illuminate\Support\Facades\DB;

/**
 * El pago del rider (§18; R7). Se paga 100% por carrera, con el tarifario del rider (Tarifas de delivery, a su nombre; R7:
 * de $1,40 a $4,00 y $0,20 por km después de 20):
 * - una entrega se paga con su ganancia de repartidor, que nace al entregarse (CommissionService y OwnDelivererCost, R11:
 *   el tramo centro → cliente, §18.1). Es el mismo monto que ve Finanzas;
 * - si llegó al cliente y no le recibieron, se paga la ida (§18.4, R7: pay_failed_leg). "Llegó" = reportó una novedad en
 *   esa orden (§13); si la cancelaron con él en camino, no hay ida. Sin tarifario no se asume un monto (§18.4);
 * - el regreso al centro no se paga (§16, §18.3) y no hay tarifa de cancelación (§18.4): no existen aquí;
 * - cierre diario (§18.5): el sistema da el total de cada rider y el operador le paga exactamente eso, con método, fecha y
 *   hora y quién pagó. Es un balance aparte del efectivo COD (§18.5, RiderCash).
 */
class RiderPay
{
    public const METHODS = [
        'efectivo_usd' => 'Efectivo en dólares',
        'efectivo_bs' => 'Efectivo en bolívares',
        'pago_movil' => 'Pago móvil',
        'transferencia' => 'Transferencia',
        'zelle' => 'Zelle',
        'binance' => 'Binance',
    ];

    public function __construct(private TariffBook $tariffs, private LogisticsSettings $settings)
    {
    }

    /** §18.4, R7: la ida, si el rider llegó al cliente y el pedido no se entregó. La llama LogisticsOrderObserver. */
    public function recordFailedLeg(LogisticsOrder $row): ?RiderTrip
    {
        $center = $row->center;
        if (!$row->rider_id || !$row->departed_at || !$center || !($this->settings->for($center)['pay_failed_leg'] ?? false)) {
            return null;
        }
        $novelty = LogisticsNovelty::where('logistics_order_id', $row->id)->where('reported_by', $row->rider_id)->latest('id')->first();
        // §14: "si una avería, accidente… impide completar un tramo, ese tramo incompleto NO se paga"
        if (!$novelty || LogisticsIncident::where('logistics_order_id', $row->id)->where('rider_id', $row->rider_id)->exists()) {
            return null;
        }
        $date = now()->toDateString();
        if (RiderTrip::where('logistics_order_id', $row->id)->where('concept', RiderTrip::FAILED_LEG)->whereDate('trip_date', $date)->exists()) {
            return null;
        }
        $tariff = $this->tariffs->find((int) $row->rider_id, (int) $center->city_id, $date);
        $quote = $tariff && $row->center_client_meters !== null ? $this->tariffs->quote($tariff, (int) $row->center_client_meters) : null;

        return RiderTrip::create([
            'rider_id' => $row->rider_id,
            'center_id' => $center->id,
            'logistics_order_id' => $row->id,
            'order_id' => $row->order_id,
            'concept' => RiderTrip::FAILED_LEG,
            'trip_date' => $date,
            'meters' => $row->center_client_meters,
            'amount_usd' => $quote ? round((float) $quote['price'], 2) : null,
            'basis' => [
                'novedad' => $novelty->type,
                'tariff_id' => $tariff?->id,
                'tariff_version' => $tariff?->version,
                'range_id' => $quote ? $quote['range']->id : null,
                'falta' => !$tariff ? 'El rider no tiene tarifario vigente.' : ($quote ? null : 'Sin distancia centro → cliente o fuera de los rangos.'),
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * Las carreras de un rider en un día: sus entregas (la ganancia de repartidor de las órdenes de este centro) y sus
     * idas pagadas.
     *
     * @return array<int, array{concept: string, label: string, order_id: ?int, order: ?string, km: ?float, amount: ?float}>
     */
    public function trips(int $riderId, LogisticsCenter $center, string $date): array
    {
        $centerOrders = LogisticsOrder::where('center_id', $center->id)->select('order_id');
        $earnings = Earning::where('user_id', $riderId)->where('role_type', 'repartidor')->whereDate('earning_date', $date)
            ->whereIn('order_id', $centerOrders)->orderBy('id')->get();
        $names = Order::whereIn('id', $earnings->pluck('order_id'))->pluck('name', 'id');
        $meters = LogisticsOrder::whereIn('order_id', $earnings->pluck('order_id'))->pluck('center_client_meters', 'order_id');
        $trips = $earnings->map(fn (Earning $e) => [
            'concept' => 'entrega',
            'label' => 'Entrega',
            'order_id' => (int) $e->order_id,
            'order' => $names[$e->order_id] ?? null,
            'km' => isset($meters[$e->order_id]) ? round($meters[$e->order_id] / 1000, 1) : null,
            'amount' => round((float) $e->amount_usd, 2),
        ])->values()->all();
        foreach (RiderTrip::with('order:id,name')->where('rider_id', $riderId)->where('center_id', $center->id)->whereDate('trip_date', $date)->orderBy('id')->get() as $t) {
            $trips[] = [
                'concept' => $t->concept,
                'label' => 'Ida (el cliente no recibió)',
                'order_id' => $t->order_id,
                'order' => $t->order?->name,
                'km' => $t->meters !== null ? round($t->meters / 1000, 1) : null,
                'amount' => $t->amount_usd,
            ];
        }

        return $trips;
    }

    /** §18.5: cuánto le toca a cada rider del centro ese día, cuánto se le pagó y el fondo que hace falta. */
    public function day(LogisticsCenter $center, string $date): array
    {
        $riders = [];
        foreach (RiderProfile::with('user:id,names')->where('center_id', $center->id)->orderBy('id')->get() as $p) {
            $trips = $this->trips($p->user_id, $center, $date);
            $payouts = RiderPayout::with('payer:id,names')->where('rider_id', $p->user_id)->where('center_id', $center->id)->whereDate('pay_date', $date)->orderBy('id')->get();
            if (!$trips && $payouts->isEmpty()) {
                continue;
            }
            $total = round(array_sum(array_map(fn ($t) => $t['amount'] ?? 0, $trips)), 2);
            $paid = round((float) $payouts->sum('amount_usd'), 2);
            $riders[] = [
                'rider_id' => $p->user_id,
                'name' => $p->user?->names,
                'trips' => $trips,
                'total' => $total,
                'paid' => $paid,
                'pending' => round($total - $paid, 2),
                'missing_tariff' => collect($trips)->contains(fn ($t) => $t['amount'] === null),
                'has_tariff' => $this->tariffs->find($p->user_id, (int) $center->city_id, $date) !== null,
                'payouts' => $payouts->map(fn (RiderPayout $x) => ['id' => $x->id, 'amount' => $x->amount_usd, 'method' => $x->method,
                    'method_label' => self::METHODS[$x->method] ?? $x->method, 'paid_at' => $x->paid_at, 'paid_by' => $x->payer?->names])->values()->all(),
            ];
        }

        return [
            'date' => $date,
            'riders' => $riders,
            'fund_needed' => round(array_sum(array_map(fn ($r) => max(0, $r['pending']), $riders)), 2),
            'methods' => self::METHODS,
        ];
    }

    /** §18.5: "el operador paga a cada rider exactamente lo indicado", con monto, método, fecha y hora y operador. */
    public function pay(LogisticsCenter $center, int $riderId, string $date, User $by, float $amount, string $method, ?string $note = null): array
    {
        if (!isset(self::METHODS[$method])) {
            return ['ok' => false, 'message' => 'Elige cómo se le pagó.', 'code' => 422];
        }

        return DB::transaction(function () use ($center, $riderId, $date, $by, $amount, $method, $note) {
            RiderProfile::where('user_id', $riderId)->lockForUpdate()->first();
            $trips = $this->trips($riderId, $center, $date);
            if (collect($trips)->contains(fn ($t) => $t['amount'] === null)) {
                return ['ok' => false, 'message' => 'Hay una carrera sin monto porque el rider no tiene tarifario: hay que cargárselo antes de pagar (§18.4).', 'code' => 422];
            }
            $total = round(array_sum(array_column($trips, 'amount')), 2);
            $paid = round((float) RiderPayout::where('rider_id', $riderId)->where('center_id', $center->id)->whereDate('pay_date', $date)->sum('amount_usd'), 2);
            $pending = round($total - $paid, 2);
            if ($pending < 0.01) {
                return ['ok' => false, 'message' => 'No hay nada pendiente de pagarle a este rider ese día.', 'code' => 409];
            }
            if (abs($amount - $pending) >= 0.01) {
                return ['ok' => false, 'message' => 'El pago tiene que ser exactamente lo que indica el sistema: USD ' . number_format($pending, 2, ',', '.') . ' (§18.5).', 'code' => 422];
            }
            RiderPayout::create([
                'rider_id' => $riderId,
                'center_id' => $center->id,
                'pay_date' => $date,
                'amount_usd' => $pending,
                'method' => $method,
                'paid_by' => $by->id,
                'paid_at' => now(),
                'note' => $note,
                'details' => ['carreras' => $trips, 'total' => $total, 'pagado_antes' => $paid],
                'created_at' => now(),
            ]);

            return ['ok' => true, 'message' => 'Pago registrado: USD ' . number_format($pending, 2, ',', '.') . ' a ' . (User::whereKey($riderId)->value('names') ?? 'el rider') . '.', 'code' => 200];
        });
    }

    /** §31.1: "Pagos de riders pendientes" del día, para el cierre de turno. */
    public function pendingToday(LogisticsCenter $center): float
    {
        return $this->day($center, now()->toDateString())['fund_needed'];
    }
}
