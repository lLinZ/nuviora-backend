<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un monto de un gasto recurrente desde una fecha (Módulo 4 de Fran, §27): los anteriores no se tocan. */
class RecurringExpenseVersion extends Model
{
    protected $fillable = ['recurring_expense_id', 'valid_from', 'is_active', 'amount', 'currency', 'rate', 'amount_usd', 'created_by'];

    protected $casts = [
        'valid_from' => 'date:Y-m-d',
        'is_active' => 'boolean',
        'amount' => 'float',
        'rate' => 'float',
        'amount_usd' => 'float',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'valid_from' => $this->valid_from?->format('Y-m-d'),
            'is_active' => $this->is_active,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'rate' => $this->rate,
            'amount_usd' => $this->amount_usd,
            'created_by' => $this->creator?->names,
        ];
    }
}
