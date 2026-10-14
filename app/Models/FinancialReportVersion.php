<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una versión de un reporte financiero (Módulo 4 de Fran, §20, §21): su contenido entero y qué cambió respecto de la anterior. */
class FinancialReportVersion extends Model
{
    protected $fillable = [
        'financial_report_id', 'version', 'status', 'summary', 'changes', 'payload', 'reason', 'generated_by', 'generated_at', 'pdf_path',
        'whatsapp_sent_at', 'whatsapp_result',
    ];

    protected $casts = [
        'version' => 'integer',
        'summary' => 'array',
        'changes' => 'array',
        'generated_at' => 'datetime',
        'whatsapp_sent_at' => 'datetime',
        'whatsapp_result' => 'array',
    ];

    public function report()
    {
        return $this->belongsTo(FinancialReport::class, 'financial_report_id');
    }

    public function generator()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** El reporte entero, tal como se generó. */
    public function content(): array
    {
        return json_decode($this->payload, true) ?: [];
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => $this->status,
            'summary' => $this->summary,
            'changes' => $this->changes,
            'reason' => $this->reason,
            'generated_by' => $this->generator?->names,
            'generated_at' => $this->generated_at?->format('Y-m-d H:i'),
            'whatsapp_sent_at' => $this->whatsapp_sent_at?->format('Y-m-d H:i'),
            'whatsapp_result' => $this->whatsapp_result,
        ];
    }
}
