<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un movimiento de caja registrado a mano (Módulo 4 de Fran, §37 a §41, §83). Da sus movimientos de cuenta en
 * `financial_transactions` (CashLedger). No se edita ni se borra: se anula con el motivo.
 */
class CashMovement extends Model
{
    public const OWNER_CONTRIBUTION = 'owner_contribution'; // §37: sube la caja, no el beneficio
    public const OWNER_WITHDRAWAL = 'owner_withdrawal';     // §38: baja la caja, no es gasto
    public const INTERNAL_TRANSFER = 'internal_transfer';   // §39 y §40: de una cuenta a otra; la comisión sí es gasto
    public const EXCHANGE = 'exchange';                     // §41: cambio de moneda
    public const ADJUSTMENT = 'adjustment';                 // §83: ajuste de saldo, con motivo
    public const AGENCY_SETTLEMENT = 'agency_settlement';   // §43: la agencia entrega el efectivo que cobró (3c)

    public const KIND_LABEL = [
        self::OWNER_CONTRIBUTION => 'Aporte del propietario',
        self::OWNER_WITHDRAWAL => 'Retiro del propietario',
        self::INTERNAL_TRANSFER => 'Transferencia entre cuentas',
        self::EXCHANGE => 'Cambio de moneda',
        self::ADJUSTMENT => 'Ajuste de saldo',
        self::AGENCY_SETTLEMENT => 'Liquidación de efectivo de agencia',
    ];

    /** Los que se registran con el diálogo de movimientos (la liquidación tiene el suyo). */
    public const MANUAL = [self::OWNER_CONTRIBUTION, self::OWNER_WITHDRAWAL, self::INTERNAL_TRANSFER, self::EXCHANGE, self::ADJUSTMENT];

    protected $fillable = [
        'kind', 'occurred_on', 'from_account_id', 'from_amount', 'to_account_id', 'to_amount', 'rate', 'fee', 'amount_usd', 'agency_id',
        'reference', 'notes', 'receipt_path', 'created_by', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'occurred_on' => 'date:Y-m-d',
        'from_amount' => 'float',
        'to_amount' => 'float',
        'rate' => 'float',
        'fee' => 'float',
        'amount_usd' => 'float',
        'voided_at' => 'datetime',
    ];

    public function fromAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'from_account_id');
    }

    public function toAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'to_account_id');
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function feeExpense()
    {
        return $this->hasOne(Expense::class);
    }

    /** R16: lo que se descontó de lo que se le debe a la agencia en esta liquidación. */
    public function compensations()
    {
        return $this->hasMany(PayablePayment::class);
    }

    public function snapshot(): array
    {
        return [
            'kind' => self::KIND_LABEL[$this->kind] ?? $this->kind,
            'occurred_on' => $this->occurred_on?->format('Y-m-d'),
            'from' => $this->fromAccount?->name,
            'from_amount' => $this->from_amount,
            'to' => $this->toAccount?->name,
            'to_amount' => $this->to_amount,
            'rate' => $this->rate,
            'fee' => $this->fee,
            'amount_usd' => $this->amount_usd,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'has_receipt' => (bool) $this->receipt_path,
        ];
    }
}
