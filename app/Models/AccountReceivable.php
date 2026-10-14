<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una cuenta por cobrar que no es el efectivo de una agencia (Módulo 4 de Fran, §45: "otras cuentas por cobrar"): un
 * préstamo, un reintegro de un proveedor… Se cobra en una o varias veces, y cada cobro entra a una cuenta de la caja.
 */
class AccountReceivable extends Model
{
    protected $table = 'accounts_receivable';

    protected $fillable = [
        'debtor_name', 'description', 'currency', 'amount', 'rate', 'amount_usd', 'collected_amount', 'collected_usd', 'issued_at', 'due_date',
        'notes', 'created_by', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'amount' => 'float',
        'rate' => 'float',
        'amount_usd' => 'float',
        'collected_amount' => 'float',
        'collected_usd' => 'float',
        'issued_at' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'voided_at' => 'datetime',
    ];

    public function collections()
    {
        return $this->hasMany(ReceivableCollection::class)->orderBy('collected_at')->orderBy('id');
    }

    public function balance(): float
    {
        return max(0.0, round($this->amount - $this->collected_amount, 2));
    }

    /** El saldo en USD: en bolívares, a la tasa de la cuenta por cobrar. */
    public function balanceUsd(): float
    {
        $balance = $this->balance();

        return in_array($this->currency, ['USD', 'USDT'], true) ? $balance : ($this->rate > 0 ? round($balance / $this->rate, 2) : 0.0);
    }

    /** collected, overdue u open. */
    public function status(): string
    {
        if ($this->balance() <= 0.0) {
            return 'collected';
        }

        return $this->due_date && $this->due_date->lt(today()) ? 'overdue' : 'open';
    }

    public function toPayload(bool $withCollections = false): array
    {
        return [
            'id' => $this->id,
            'debtor_name' => $this->debtor_name,
            'description' => $this->description,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'rate' => $this->rate,
            'amount_usd' => $this->amount_usd,
            'collected_amount' => $this->collected_amount,
            'balance' => $this->balance(),
            'balance_usd' => $this->balanceUsd(),
            'issued_at' => $this->issued_at?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status(),
            'notes' => $this->notes,
        ] + ($withCollections ? ['collections' => $this->collections->map->toPayload()->values()] : []);
    }
}
