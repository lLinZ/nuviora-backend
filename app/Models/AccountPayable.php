<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuenta por pagar (Módulo 4 de Fran, §16, §17, §44): beneficiario, monto, vencimiento, pagado, pendiente y vencido.
 * Genérica: hoy la crean las compras de inventario; en la entrega 3, los cierres de las agencias (§101). El monto y lo
 * pagado van en la moneda de la deuda; amount_usd es el equivalente a la tasa del día de la deuda.
 */
class AccountPayable extends Model
{
    protected $table = 'accounts_payable';

    public const SOURCE_PURCHASE = 'inventory_purchase';
    public const PAYEE_SUPPLIER = 'supplier';

    protected $fillable = [
        'payee_type', 'supplier_id', 'agency_id', 'payee_name', 'source_type', 'source_id', 'description', 'currency', 'amount',
        'rate', 'amount_usd', 'paid_amount', 'paid_usd', 'issued_at', 'due_date', 'terms', 'voided_at', 'void_reason', 'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'rate' => 'float',
        'amount_usd' => 'float',
        'paid_amount' => 'float',
        'paid_usd' => 'float',
        'issued_at' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'voided_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayablePayment::class)->orderBy('paid_at')->orderBy('id');
    }

    /** Lo que falta por pagar, en la moneda de la deuda. */
    public function balance(): float
    {
        return max(0.0, round($this->amount - $this->paid_amount, 2));
    }

    /** El saldo en USD: igual al saldo en USD y USDT; en bolívares, a la tasa de la deuda (aproximado). */
    public function balanceUsd(): float
    {
        $balance = $this->balance();

        return in_array($this->currency, ['USD', 'USDT'], true) ? $balance : ($this->rate > 0 ? round($balance / $this->rate, 2) : 0.0);
    }

    /** paid, overdue (vencida con saldo) u open. */
    public function status(): string
    {
        if ($this->balance() <= 0.0) {
            return 'paid';
        }

        return $this->due_date && $this->due_date->lt(today()) ? 'overdue' : 'open';
    }

    public function payeeName(): string
    {
        return $this->supplier?->name ?? $this->agency?->names ?? (string) $this->payee_name;
    }

    public function toPayload(bool $withPayments = false): array
    {
        return [
            'id' => $this->id,
            'payee_type' => $this->payee_type,
            'payee' => $this->payeeName(),
            'supplier_id' => $this->supplier_id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'description' => $this->description,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'rate' => $this->rate,
            'amount_usd' => $this->amount_usd,
            'paid_amount' => $this->paid_amount,
            'paid_usd' => $this->paid_usd,
            'balance' => $this->balance(),
            'balance_usd' => $this->balanceUsd(),
            'issued_at' => $this->issued_at?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'terms' => $this->terms,
            'status' => $this->status(),
        ] + ($withPayments ? ['payments' => $this->payments->map->toPayload()->values()] : []);
    }
}
