<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un cobro de una cuenta por cobrar (Módulo 4 de Fran, §45): entra a una cuenta de la caja. Se anula con motivo (§83). */
class ReceivableCollection extends Model
{
    protected $fillable = [
        'account_receivable_id', 'amount', 'currency', 'rate', 'amount_usd', 'applied_amount', 'collected_at', 'financial_account_id', 'notes',
        'receipt_path', 'created_by', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'amount' => 'float',
        'rate' => 'float',
        'amount_usd' => 'float',
        'applied_amount' => 'float',
        'collected_at' => 'date:Y-m-d',
        'voided_at' => 'datetime',
    ];

    public function receivable()
    {
        return $this->belongsTo(AccountReceivable::class, 'account_receivable_id');
    }

    public function account()
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'rate' => $this->rate,
            'amount_usd' => $this->amount_usd,
            'applied_amount' => $this->applied_amount,
            'collected_at' => $this->collected_at?->toDateString(),
            'account' => $this->account?->name,
            'notes' => $this->notes,
            'has_receipt' => $this->receipt_path !== null,
            'voided_at' => $this->voided_at?->format('Y-m-d H:i'),
            'void_reason' => $this->void_reason,
        ];
    }
}
