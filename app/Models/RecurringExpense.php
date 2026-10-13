<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Gasto recurrente (Módulo 4 de Fran, §25 a §28): editor, Shopify, ChatGPT… El monto vive en versiones (§27).
 * Para la rentabilidad, cada pago se reparte en los días que cubre (§28): un pago semanal es 1/7 por día y uno mensual se
 * reparte entre los días de su mes. Para la caja (entrega 3) cuenta el día de pago.
 */
class RecurringExpense extends Model
{
    public const FREQUENCIES = ['weekly' => 'Semanal', 'monthly' => 'Mensual', 'annual' => 'Anual', 'custom' => 'Cada cierta cantidad de días'];
    public const WEEKDAYS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    protected $fillable = ['name', 'expense_category_id', 'frequency', 'interval_days', 'payment_day', 'payment_date', 'product_id', 'city_id',
        'is_active', 'created_by'];

    protected $casts = [
        'is_active' => 'boolean',
        'payment_date' => 'date:Y-m-d',
        'interval_days' => 'integer',
        'payment_day' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function versions()
    {
        return $this->hasMany(RecurringExpenseVersion::class)->orderBy('valid_from');
    }

    /** La versión vigente ese día (la última que empezó en o antes). */
    public function versionOn(CarbonInterface $day): ?RecurringExpenseVersion
    {
        return $this->versions->filter(fn ($v) => $v->valid_from->lte($day))->last();
    }

    /** §28: lo que le toca a un día. */
    public function dailyCost(RecurringExpenseVersion $v, CarbonInterface $day): float
    {
        if (!$v->is_active) {
            return 0.0;
        }

        return match ($this->frequency) {
            'weekly' => $v->amount_usd / 7,
            'monthly' => $v->amount_usd / $day->daysInMonth,
            'annual' => $v->amount_usd / ($day->isLeapYear() ? 366 : 365),
            'custom' => $v->amount_usd / max(1, (int) $this->interval_days),
            default => 0.0,
        };
    }

    /** Lo que se reparte entre dos fechas, con las versiones de cada día (§27, §28). */
    public function accrued(CarbonInterface $from, CarbonInterface $to): float
    {
        $total = 0.0;
        $first = $this->versions->first()?->valid_from;
        if (!$first) {
            return 0.0;
        }
        for ($day = $from->copy()->max($first)->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            if ($v = $this->versionOn($day)) {
                $total += $this->dailyCost($v, $day);
            }
        }

        return round($total, 2);
    }

    /** Cuánto es al mes, para comparar gastos de distinta frecuencia. */
    public function monthlyEquivalent(?RecurringExpenseVersion $v): float
    {
        if (!$v || !$v->is_active) {
            return 0.0;
        }

        return round(match ($this->frequency) {
            'weekly' => $v->amount_usd * 52 / 12,
            'monthly' => $v->amount_usd,
            'annual' => $v->amount_usd / 12,
            'custom' => $v->amount_usd * 365 / 12 / max(1, (int) $this->interval_days),
            default => 0.0,
        }, 2);
    }

    /** El próximo día de pago desde una fecha (incluida), §26: "cada viernes", "día 23 de cada mes". */
    public function nextPayment(CarbonInterface $from): ?CarbonInterface
    {
        $from = $from->copy()->startOfDay();

        return match ($this->frequency) {
            'weekly' => $this->payment_day ? $from->copy()->addDays(($this->payment_day - $from->dayOfWeekIso + 7) % 7) : null,
            'monthly' => $this->payment_day ? $this->monthDay($from, $this->payment_day) : null,
            'annual' => $this->payment_date ? $this->yearDay($from) : null,
            'custom' => $this->payment_date && $this->interval_days ? $this->everyDays($from) : null,
            default => null,
        };
    }

    /** "Día 31" en un mes de 30 días es el último del mes. */
    private function monthDay(CarbonInterface $from, int $dayOfMonth): CarbonInterface
    {
        $candidate = $from->copy()->startOfMonth()->addDays(min($dayOfMonth, $from->daysInMonth) - 1);
        if ($candidate->lt($from)) {
            $next = $from->copy()->startOfMonth()->addMonthNoOverflow();
            $candidate = $next->addDays(min($dayOfMonth, $next->daysInMonth) - 1);
        }

        return $candidate;
    }

    private function yearDay(CarbonInterface $from): CarbonInterface
    {
        $candidate = $from->copy()->setDate($from->year, $this->payment_date->month, min($this->payment_date->day, Carbon::create($from->year, $this->payment_date->month)->daysInMonth));

        return $candidate->lt($from) ? $candidate->addYearNoOverflow() : $candidate;
    }

    private function everyDays(CarbonInterface $from): CarbonInterface
    {
        $anchor = $this->payment_date->copy()->startOfDay();
        if ($anchor->gte($from)) {
            return $anchor;
        }
        $steps = (int) ceil($anchor->diffInDays($from) / $this->interval_days);

        return $anchor->addDays($steps * $this->interval_days);
    }

    public function scheduleLabel(): string
    {
        return match ($this->frequency) {
            'weekly' => 'Cada ' . (self::WEEKDAYS[$this->payment_day] ?? 'semana'),
            'monthly' => 'El día ' . $this->payment_day . ' de cada mes',
            'annual' => 'Cada ' . ($this->payment_date ? $this->payment_date->locale('es')->translatedFormat('j \d\e F') : 'año'),
            'custom' => "Cada {$this->interval_days} días",
            default => '',
        };
    }

    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category?->name,
            'frequency' => self::FREQUENCIES[$this->frequency] ?? $this->frequency,
            'schedule' => $this->scheduleLabel(),
            'product' => $this->product?->title,
            'city' => $this->city?->name,
            'is_active' => $this->is_active,
        ];
    }

    public function toPayload(?CarbonInterface $today = null): array
    {
        $today ??= now();
        $today = $today->copy()->startOfDay();
        $todayVersion = $this->versionOn($today);
        $current = $todayVersion ?? $this->versions->first();
        // Un cambio que todavía no empieza (un aumento o una baja con fecha futura)
        $scheduled = $this->versions->first(fn ($v) => $v->valid_from->gt($today));
        $activeToday = (bool) $todayVersion?->is_active;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'expense_category_id' => $this->expense_category_id,
            'category' => $this->category?->name,
            'frequency' => $this->frequency,
            'frequency_label' => self::FREQUENCIES[$this->frequency] ?? $this->frequency,
            'interval_days' => $this->interval_days,
            'payment_day' => $this->payment_day,
            'payment_date' => $this->payment_date?->format('Y-m-d'),
            'schedule' => $this->scheduleLabel(),
            'product_id' => $this->product_id,
            'product' => $this->product?->title,
            'city_id' => $this->city_id,
            'city' => $this->city?->name,
            'scope' => $this->product_id || $this->city_id ? 'assigned' : 'general',
            'is_active' => $this->is_active,
            'active_today' => $activeToday,
            'current' => $current?->toPayload(),
            'scheduled' => $scheduled?->toPayload(),
            'monthly_usd' => $this->monthlyEquivalent($current),
            'next_payment' => $activeToday ? $this->nextPayment($today)?->format('Y-m-d') : null,
            'versions' => $this->versions->reverse()->values()->map->toPayload(),
        ];
    }
}
