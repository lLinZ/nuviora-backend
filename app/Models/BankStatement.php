<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un extracto subido para una conciliación (documento de Fran del 2026-10-06, §16 y §26). El archivo está en el disco
 * privado. Si se reemplaza, el anterior queda guardado con quién lo reemplazó y cuándo.
 */
class BankStatement extends Model
{
    protected $fillable = [
        'reconciliation_day_id', 'statement_source_id', 'path', 'original_name', 'sha256', 'date_from', 'date_to',
        'rows_read', 'rows_credit', 'uploaded_by', 'replaced_by_id', 'replaced_by_user', 'replaced_at',
    ];

    protected $casts = [
        'date_from' => 'date:Y-m-d',
        'date_to' => 'date:Y-m-d',
        'replaced_at' => 'datetime',
    ];

    public function day()
    {
        return $this->belongsTo(ReconciliationDay::class, 'reconciliation_day_id');
    }

    public function source()
    {
        return $this->belongsTo(StatementSource::class, 'statement_source_id');
    }

    public function rows()
    {
        return $this->hasMany(BankStatementRow::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Los que están en uso (no reemplazados). */
    public function scopeCurrent($query)
    {
        return $query->whereNull('replaced_at');
    }
}
