<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pago de una cuenta por pagar (§17): reduce la deuda y no cambia el costo del inventario. Su moneda, tasa y USD (§32,
 * R2) y lo que descuenta de la deuda (applied_amount, en la moneda de la deuda). No se borra: se anula con motivo (§83).
 */
class PayablePayment extends Model
{
    protected $fillable = [
        'account_payable_id', 'amount', 'currency', 'rate', 'amount_usd', 'applied_amount', 'paid_at', 'company_account_id', 'financial_account_id', 'cash_movement_id',
        'receipt_path', 'notes', 'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'rate' => 'float',
        'amount_usd' => 'float',
        'applied_amount' => 'float',
        'paid_at' => 'date:Y-m-d',
        'voided_at' => 'datetime',
    ];

    public function payable(): BelongsTo
    {
        return $this->belongsTo(AccountPayable::class, 'account_payable_id');
    }

    /** La cuenta de la caja de donde salió (entrega 3). En una compensación, el efectivo de la agencia (R16). */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function snapshot(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'rate' => $this->rate,
            'amount_usd' => $this->amount_usd,
            'applied_amount' => $this->applied_amount,
            'paid_at' => $this->paid_at?->format('Y-m-d'),
            'account' => $this->account?->name,
            'notes' => $this->notes,
            'has_receipt' => $this->receipt_path !== null,
        ];
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
            'paid_at' => $this->paid_at?->format('Y-m-d'),
            'financial_account_id' => $this->financial_account_id,
            'account' => $this->account?->name,
            'cash_movement_id' => $this->cash_movement_id,
            'notes' => $this->notes,
            'has_receipt' => $this->receipt_path !== null,
            'voided_at' => $this->voided_at?->format('Y-m-d H:i'),
            'voided_by' => $this->voider?->names,
            'void_reason' => $this->void_reason,
            'created_by' => $this->creator?->names,
        ];
    }
}
