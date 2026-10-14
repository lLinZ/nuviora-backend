<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un reporte financiero de un período (Módulo 4 de Fran, §53 a §56). Sus cifras viven en sus versiones: consolidado no es
 * congelado (§20), y una entrega tardía crea otra versión (§21) sin borrar la anterior (§83).
 */
class FinancialReport extends Model
{
    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';
    public const MONTHLY = 'monthly';
    public const CUSTOM = 'custom';

    public const TYPES = [self::DAILY => 'Diario', self::WEEKLY => 'Semanal', self::MONTHLY => 'Mensual', self::CUSTOM => 'Personalizado'];

    protected $fillable = ['type', 'period_from', 'period_to', 'trigger', 'current_version', 'created_by'];

    protected $casts = [
        'period_from' => 'date:Y-m-d',
        'period_to' => 'date:Y-m-d',
        'current_version' => 'integer',
    ];

    public function versions()
    {
        return $this->hasMany(FinancialReportVersion::class)->orderByDesc('version');
    }

    public function current()
    {
        return $this->hasOne(FinancialReportVersion::class)->ofMany('version', 'max');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "Diario del 09/10/2026", "Semanal del 05/10 al 11/10/2026", "Mensual de septiembre 2026"… */
    public function title(): string
    {
        $from = $this->period_from;
        $to = $this->period_to;
        if ($this->type === self::MONTHLY) {
            return 'Mensual de ' . $from->locale('es')->translatedFormat('F Y');
        }
        $range = $from->equalTo($to) ? 'del ' . $from->format('d/m/Y') : 'del ' . $from->format('d/m') . ' al ' . $to->format('d/m/Y');

        return (self::TYPES[$this->type] ?? 'Reporte') . ' ' . $range;
    }

    public function toPayload(): array
    {
        $v = $this->current;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => self::TYPES[$this->type] ?? $this->type,
            'title' => $this->title(),
            'from' => $this->period_from->toDateString(),
            'to' => $this->period_to->toDateString(),
            'trigger' => $this->trigger,
            'created_by' => $this->creator?->names,
            'version' => $v?->version,
            'versions' => $this->versions_count ?? null,
            'status' => $v?->status,
            'summary' => $v?->summary,
            'changes' => $v?->changes,
            'reason' => $v?->reason,
            'generated_at' => $v?->generated_at?->format('Y-m-d H:i'),
            'whatsapp_sent_at' => $v?->whatsapp_sent_at?->format('Y-m-d H:i'),
            'whatsapp_result' => $v?->whatsapp_result,
        ];
    }
}
