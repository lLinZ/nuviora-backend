<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El pago de un vencimiento de un gasto recurrente (Módulo 4 de Fran, §28): sale de la caja el día que se pagó. La
 * rentabilidad no cambia: el recurrente se sigue repartiendo en los días que cubre. Se anula con motivo (§83).
 */
class RecurringExpensePayment extends Model
{
    protected $fillable = [
        'recurring_expense_id', 'due_date', 'paid_at', 'financial_account_id', 'amount', 'currency', 'rate', 'amount_usd', 'notes', 'receipt_path',
        'created_by', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'paid_at' => 'date:Y-m-d',
        'amount' => 'float',
        'rate' => 'float',
        'amount_usd' => 'float',
        'voided_at' => 'datetime',
    ];

    public function recurring()
    {
        return $this->belongsTo(RecurringExpense::class, 'recurring_expense_id');
    }

    public function account()
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'due_date' => $this->due_date?->toDateString(),
            'paid_at' => $this->paid_at?->toDateString(),
            'account' => $this->account?->name,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'rate' => $this->rate,
            'amount_usd' => $this->amount_usd,
            'notes' => $this->notes,
            'has_receipt' => $this->receipt_path !== null,
            'voided_at' => $this->voided_at?->format('Y-m-d H:i'),
            'void_reason' => $this->void_reason,
        ];
    }
}
