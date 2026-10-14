<?php

namespace App\Services\Finance;

use App\Models\AgencyWeeklyClosure;
use App\Models\City;
use App\Models\FinancialAuditLog;
use App\Models\FinancialReport;
use App\Models\FinancialReportVersion;
use App\Models\InventoryTransferTrip;
use App\Models\Product;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Los reportes financieros (Módulo 4 de Fran, entrega 4):
 * - §53: diario, D-3 por defecto (hoy 12 → el día 9). §54: semanal, de lunes a domingo, el miércoles siguiente.
 *   §55: mensual, el mes anterior, el día 7. Todo "configurable por administración" (finance.reports). La hora no la
 *   dice el documento: 08:00 es decisión nuestra.
 * - §56: cualquier rango a mano. §57: hoy, semana o mes actual salen PROVISIONAL (lo marca ProfitAndLoss). §58: cada
 *   reporte se compara con el período anterior del mismo largo.
 * - §20, §21: consolidado no es congelado. Cada noche se recalculan los reportes recientes y, si cambió una cifra clave,
 *   nace otra versión que dice qué cambió; la anterior no se borra (§83).
 * El contenido sale de los mismos servicios que las pantallas: rentabilidad, cohortes, CPA y caja.
 */
class FinancialReports
{
    public const SETTING = 'finance.reports';

    /** Lo que se recalcula cada noche: los reportes que terminan en los últimos N días. */
    public const REFRESH_DAYS = 60;

    public const DEFAULTS = [
        'daily' => ['enabled' => true, 'lag_days' => 3, 'time' => '08:00'],
        'weekly' => ['enabled' => true, 'weekday' => 3, 'time' => '08:00'],
        'monthly' => ['enabled' => true, 'day' => 7, 'time' => '08:00'],
        // R18: el envío por WhatsApp necesita una plantilla aprobada por Meta, con el PDF en el encabezado
        'whatsapp' => ['enabled' => false, 'to' => [], 'template' => null, 'language' => 'es', 'types' => ['daily', 'weekly', 'monthly']],
    ];

    /** Las cifras que se comparan entre versiones (§21), con su nombre. */
    public const KEY_FIGURES = [
        'revenue' => 'Ingresos',
        'cogs' => 'COGS',
        'logistics' => 'Logística',
        'delivery' => 'Delivery',
        'commission_sellers' => 'Comisiones vendedoras',
        'commission_leaders' => 'Comisión líderes',
        'advertising' => 'Publicidad',
        'other_variable' => 'Otros variables',
        'contribution' => 'Margen de contribución',
        'fixed' => 'Gastos generales',
        'operating_profit' => 'Beneficio operativo',
        'orders_delivered' => 'Órdenes entregadas',
        'orders_not_delivered' => 'Órdenes no entregadas con costo',
    ];

    public function __construct(private ProfitAndLoss $pnl, private CohortReport $cohorts, private FinancialCpa $cpa, private CashDashboard $cash)
    {
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Configuración (§53 a §55)

    public static function config(): array
    {
        $saved = Setting::get(self::SETTING, []);
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::DEFAULTS as $key => $defaults) {
            $out[$key] = array_merge($defaults, array_intersect_key((array) ($saved[$key] ?? []), $defaults));
        }

        return $out;
    }

    public static function saveConfig(array $config, int $userId): array
    {
        $before = self::config();
        $after = [];
        foreach (self::DEFAULTS as $key => $defaults) {
            $after[$key] = array_merge($before[$key], array_intersect_key((array) ($config[$key] ?? []), $defaults));
        }
        $after['whatsapp']['to'] = array_values(array_unique(array_filter(array_map(fn ($n) => preg_replace('/\D/', '', (string) $n), (array) $after['whatsapp']['to']))));
        if ($after !== $before) {
            Setting::set(self::SETTING, $after);
            FinancialAuditLog::record('finance_reports_config_updated', 'setting', null, $before, $after, null, $userId);
        }

        return $after;
    }

    /**
     * El último período de un tipo que ya debía generarse a esta hora, o null si está desactivado.
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function duePeriod(string $type, CarbonInterface $now, ?array $config = null): ?array
    {
        $c = ($config ?? self::config())[$type] ?? null;
        if (!$c || !$c['enabled']) {
            return null;
        }
        $now = Carbon::instance($now);
        [$h, $m] = array_map('intval', explode(':', (string) $c['time']) + [0, 0]);
        $at = fn (CarbonInterface $day) => Carbon::instance($day)->copy()->setTime($h, $m);

        if ($type === FinancialReport::DAILY) {
            // §53: D-3 → si hoy ya pasó la hora, el de hoy − 3; si no, el de ayer − 3
            $day = $now->copy()->startOfDay()->subDays((int) $c['lag_days'] + ($now->lt($at($now)) ? 1 : 0));

            return [$day, $day->copy()];
        }
        if ($type === FinancialReport::WEEKLY) {
            // §54: el último "miércoles a las 08:00" que ya pasó; la semana es la de lunes a domingo que terminó antes
            $moment = $now->copy()->startOfDay();
            while ($moment->dayOfWeekIso !== (int) $c['weekday'] || $at($moment)->gt($now)) {
                $moment->subDay();
            }
            $sunday = $moment->copy()->subDay();
            while ($sunday->dayOfWeekIso !== 7) {
                $sunday->subDay();
            }

            return [$sunday->copy()->subDays(6), $sunday];
        }
        if ($type === FinancialReport::MONTHLY) {
            // §55: el último "día 7 a las 08:00" que ya pasó; el período es el mes calendario anterior a ese
            $day = min(28, max(1, (int) $c['day']));
            $moment = $now->copy()->startOfMonth()->addDays($day - 1);
            if ($at($moment)->gt($now)) {
                $moment = $now->copy()->startOfMonth()->subMonth()->addDays($day - 1);
            }
            $month = $moment->copy()->startOfMonth()->subMonth();

            return [$month, $month->copy()->endOfMonth()->startOfDay()];
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // El contenido

    /** Todo lo que lleva un reporte del período. */
    public function build(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = Carbon::instance($from)->startOfDay();
        $to = Carbon::instance($to)->startOfDay();
        $byProduct = $this->pnl->report($from, $to, 'product');
        $byCity = $this->pnl->report($from, $to, 'city');
        [$prevFrom, $prevTo] = ProfitAndLoss::previousPeriod($from, $to);
        $previous = $this->pnl->report($prevFrom, $prevTo);
        $cohorts = $this->cohorts->report($from, $to, $from->diffInDays($to) > 31 ? 'week' : 'day');
        $trips = InventoryTransferTrip::whereNull('voided_at')->whereBetween('trip_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) AS trips, COALESCE(SUM(total_usd), 0) AS total_usd, COALESCE(SUM(units), 0) AS units')->first();
        // §69: el CPA financiero vigente de cada producto + ciudad
        $cpa = collect($this->cpa->latest())->filter(fn ($v) => $v->city_id !== null);
        $products = Product::whereIn('id', $cpa->pluck('product_id'))->pluck('title', 'id');
        $cities = City::whereIn('id', $cpa->pluck('city_id'))->pluck('name', 'id');
        $group = fn (array $g) => array_intersect_key($g, array_flip(['name', 'revenue', 'cogs', 'logistics', 'delivery', 'commission_sellers',
            'commission_leaders', 'advertising', 'other_variable', 'contribution', 'margin_pct', 'orders_delivered', 'orders_not_delivered', 'unassigned']));

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $from->diffInDays($to) + 1,
            'generated_at' => now()->format('Y-m-d H:i'),
            'status' => $byProduct['status'],
            'company' => $byProduct['company'],
            'previous' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString(), 'status' => $previous['status'], 'company' => $previous['company']],
            'by_product' => array_map($group, $byProduct['groups'] ?? []),
            'by_city' => array_map($group, $byCity['groups'] ?? []),
            'warnings' => $byProduct['warnings'],
            'cohorts' => [
                'total' => $cohorts['total'],
                'mature_until' => $cohorts['mature_until'],
                'effectiveness' => array_slice($cohorts['effectiveness']->all(), 0, 15),
            ],
            'cpa' => $cpa->map(fn ($v) => [
                'product_id' => $v->product_id, 'city_id' => $v->city_id,
                'name' => ($products[$v->product_id] ?? "Producto {$v->product_id}") . ' / ' . ($cities[$v->city_id] ?? "Ciudad {$v->city_id}"),
                'period_from' => $v->period_from?->toDateString(), 'period_to' => $v->period_to?->toDateString(),
                'purchases' => (float) $v->purchases, 'meta_cpa' => $v->meta_cpa !== null ? (float) $v->meta_cpa : null,
                'break_even' => $v->break_even !== null ? (float) $v->break_even : null, 'target' => $v->target !== null ? (float) $v->target : null,
                'fully_loaded' => $v->fully_loaded !== null ? (float) $v->fully_loaded : null, 'viable' => (bool) $v->viable,
            ])->sortBy('name')->values()->all(),
            // §46: la caja tal como estaba al generar el reporte (no es del período: es una foto)
            'cash' => $this->cash->build($from->toDateString(), $to->toDateString()),
            // §13, §110: cuánto se gastó trasladando inventario
            'transport' => ['trips' => (int) $trips->trips, 'total_usd' => round((float) $trips->total_usd, 2), 'units' => (int) $trips->units],
            // §110: qué cierres de agencias están pendientes y quién no ha subido el suyo
            'closures_pending' => AgencyWeeklyClosure::with('agency:id,names')
                ->whereNotIn('status', [AgencyWeeklyClosure::READY, AgencyWeeklyClosure::PAID])->orderBy('period_start')->get()
                ->map(fn (AgencyWeeklyClosure $c) => ['agency' => $c->agency?->names, 'from' => $c->period_start?->toDateString(),
                    'to' => $c->period_end?->toDateString(), 'status' => $c->status])->values()->all(),
        ];
    }

    /** Las cifras clave de un reporte, para comparar versiones (§21). */
    public static function summary(array $payload): array
    {
        $c = $payload['company'] ?? [];
        $out = ['status' => $payload['status']['label'] ?? null];
        foreach (array_keys(self::KEY_FIGURES) as $key) {
            $out[$key] = isset($c[$key]) ? round((float) $c[$key], 2) : 0.0;
        }

        return $out;
    }

    /** Qué cambió entre dos resúmenes: [{key, label, before, after}]. */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        if (($before['status'] ?? null) !== ($after['status'] ?? null)) {
            $out[] = ['key' => 'status', 'label' => 'Estado', 'before' => $before['status'] ?? null, 'after' => $after['status'] ?? null];
        }
        foreach (self::KEY_FIGURES as $key => $label) {
            $a = round((float) ($before[$key] ?? 0), 2);
            $b = round((float) ($after[$key] ?? 0), 2);
            if (abs($a - $b) >= 0.005) {
                $out[] = ['key' => $key, 'label' => $label, 'before' => $a, 'after' => $b];
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Generar y actualizar

    /**
     * Genera el reporte de un período. Si ya existe, lo recalcula: si cambió algo nace otra versión; si no, queda la que hay.
     * @return array{report: FinancialReport, version: FinancialReportVersion, created: bool}
     */
    public function generate(string $type, CarbonInterface $from, CarbonInterface $to, string $trigger, ?int $userId, ?string $reason = null): array
    {
        if (!isset(FinancialReport::TYPES[$type])) {
            throw ValidationException::withMessages(['type' => 'Ese tipo de reporte no existe.']);
        }
        if ($from->gt($to)) {
            throw ValidationException::withMessages(['from' => 'La fecha de inicio tiene que ser antes del fin.']);
        }
        $payload = $this->build($from, $to);

        return DB::transaction(function () use ($type, $from, $to, $trigger, $userId, $reason, $payload) {
            $report = FinancialReport::where('type', $type)->whereDate('period_from', $from->toDateString())->whereDate('period_to', $to->toDateString())
                ->lockForUpdate()->first();
            if ($report) {
                $version = $this->addVersionIfChanged($report, $payload, $reason ?? 'Generado de nuevo', $userId);

                return ['report' => $report->fresh(), 'version' => $version ?? $report->current()->first(), 'created' => $version !== null];
            }
            $report = FinancialReport::create(['type' => $type, 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(),
                'trigger' => $trigger, 'current_version' => 1, 'created_by' => $userId]);
            $version = $report->versions()->create([
                'version' => 1,
                'status' => $payload['status']['label'],
                'summary' => self::summary($payload),
                'changes' => null,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'reason' => $reason ?? ($trigger === 'auto' ? 'Generado automáticamente' : 'Generado a mano'),
                'generated_by' => $userId,
                'generated_at' => now(),
            ]);

            return ['report' => $report, 'version' => $version, 'created' => true];
        });
    }

    /** §20, §21: recalcula un reporte; si cambió una cifra clave o su estado, nace otra versión. Devuelve la nueva o null. */
    public function refresh(FinancialReport $report, string $reason, ?int $userId = null): ?FinancialReportVersion
    {
        $payload = $this->build($report->period_from, $report->period_to);

        return DB::transaction(function () use ($report, $payload, $reason, $userId) {
            $report = FinancialReport::whereKey($report->id)->lockForUpdate()->firstOrFail();

            return $this->addVersionIfChanged($report, $payload, $reason, $userId);
        });
    }

    private function addVersionIfChanged(FinancialReport $report, array $payload, string $reason, ?int $userId): ?FinancialReportVersion
    {
        $current = $report->current()->first();
        $summary = self::summary($payload);
        $changes = $current ? self::diff($current->summary ?? [], $summary) : [];
        if ($current && $changes === []) {
            return null;
        }
        $number = (int) $report->versions()->max('version') + 1;
        $version = $report->versions()->create([
            'version' => $number,
            'status' => $payload['status']['label'],
            'summary' => $summary,
            'changes' => $changes ?: null,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
            'generated_by' => $userId,
            'generated_at' => now(),
        ]);
        $report->update(['current_version' => $number]);

        return $version;
    }

    /**
     * Lo que corre cada 10 minutos: genera el último reporte diario, semanal y mensual que ya debía existir y no existe.
     * @return array<int, FinancialReportVersion>
     */
    public function runSchedule(CarbonInterface $now): array
    {
        $config = self::config();
        $made = [];
        foreach ([FinancialReport::DAILY, FinancialReport::WEEKLY, FinancialReport::MONTHLY] as $type) {
            $period = self::duePeriod($type, $now, $config);
            if (!$period) {
                continue;
            }
            [$from, $to] = $period;
            $exists = FinancialReport::where('type', $type)->whereDate('period_from', $from->toDateString())->whereDate('period_to', $to->toDateString())->exists();
            if (!$exists) {
                $made[] = $this->generate($type, $from, $to, 'auto', null)['version'];
            }
        }

        return $made;
    }

    /**
     * Cada noche: los reportes que terminan en los últimos REFRESH_DAYS días se recalculan (§20, §21).
     * @return array<int, FinancialReportVersion> las versiones nuevas
     */
    public function refreshRecent(): array
    {
        $made = [];
        $reports = FinancialReport::whereDate('period_to', '>=', today()->subDays(self::REFRESH_DAYS)->toDateString())->orderBy('period_from')->get();
        foreach ($reports as $report) {
            try {
                if ($v = $this->refresh($report, 'Cambiaron las cifras del período (entregas tardías, cierres aprobados o costos)')) {
                    $made[] = $v;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $made;
    }
}
