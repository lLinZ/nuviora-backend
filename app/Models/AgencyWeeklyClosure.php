<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * El cierre semanal de una agencia (Módulo 3 de Fran, §20 a §37, §46): una semana de lunes a domingo, el archivo que
 * sube la agencia, lo que el sistema calculó, lo que la agencia cobra y el monto final aprobado.
 */
class AgencyWeeklyClosure extends Model
{
    // §36
    public const PENDING_FILE = 'pendiente_archivo';
    public const FILE_RECEIVED = 'archivo_recibido';
    public const PROCESSING = 'procesando';
    public const PENDING_REVIEW = 'pendiente_revision';
    public const READY = 'listo_pago';
    public const PAID = 'pagado';

    public const STATUS_LABEL = [
        self::PENDING_FILE => 'Pendiente de archivo',
        self::FILE_RECEIVED => 'Archivo recibido',
        self::PROCESSING => 'Procesando',
        self::PENDING_REVIEW => 'Pendiente de revisión',
        self::READY => 'Listo para pago',
        self::PAID => 'Pagado',
    ];

    /** §38: lo que ve la agencia. Sin montos internos ni discrepancias: solo en qué punto está. */
    public const AGENCY_LABEL = [
        self::PENDING_FILE => 'Pendiente',
        self::FILE_RECEIVED => 'Archivo recibido',
        self::PROCESSING => 'Archivo recibido',
        self::PENDING_REVIEW => 'En revisión',
        self::READY => 'Procesado',
        self::PAID => 'Pagado',
    ];

    protected $fillable = [
        'agency_id', 'period_start', 'period_end', 'status', 'trips_count', 'expected_total', 'reported_total',
        'final_approved_total', 'manual_total', 'manual_reason', 'summary', 'processing_error', 'uploaded_at', 'processed_at', 'approved_at', 'approved_by',
        'paid_at', 'paid_by', 'payment_amount', 'payment_date', 'payment_method', 'payment_reference', 'payment_receipt_path',
    ];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'expected_total' => 'float',
        'reported_total' => 'float',
        'final_approved_total' => 'float',
        'manual_total' => 'float',
        'payment_amount' => 'float',
        'payment_date' => 'date:Y-m-d',
        'summary' => 'array',
        'uploaded_at' => 'datetime',
        'processed_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function files()
    {
        return $this->hasMany(AgencyClosureFile::class, 'closure_id')->orderByDesc('version');
    }

    public function currentFile()
    {
        return $this->hasOne(AgencyClosureFile::class, 'closure_id')->where('is_current', true)->latestOfMany('version');
    }

    public function rows()
    {
        return $this->hasMany(AgencyClosureRow::class, 'closure_id')->where('is_current', true)->orderBy('position');
    }

    public function discrepancies()
    {
        return $this->hasMany(AgencyClosureDiscrepancy::class, 'closure_id')->where('is_current', true);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** "5–11 octubre" (§23, §31). */
    public function weekLabel(): string
    {
        $from = $this->period_start;
        $to = $this->period_end;
        $month = fn (Carbon $d) => $d->copy()->locale('es')->translatedFormat('F');

        return $from->month === $to->month
            ? "{$from->day}–{$to->day} {$month($to)}"
            : "{$from->day} {$month($from)} – {$to->day} {$month($to)}";
    }

    /** §21 y §22: el momento del aviso, en la semana siguiente (por defecto, el miércoles a las 8:00). */
    public function reminderAt(AgencyClosureSetting $setting): Carbon
    {
        $day = max(1, min(7, (int) $setting->closing_day));

        return Carbon::parse($this->period_end->toDateString() . ' ' . $setting->timeLabel(), config('app.timezone'))->addDays($day);
    }

    /** §22: se ve desde el día del aviso hasta que la agencia sube el archivo. */
    public function reminderDue(AgencyClosureSetting $setting, ?Carbon $now = null): bool
    {
        return $setting->reminder_active && $this->status === self::PENDING_FILE
            && ($now ?? now(config('app.timezone')))->greaterThanOrEqualTo($this->reminderAt($setting));
    }
}
