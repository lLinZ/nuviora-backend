<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un movimiento de una cuenta de la caja (Módulo 4 de Fran, §32 y §83): monto con signo en la moneda de la cuenta, tasa
 * y USD, y el origen del que nace. No se borra: si el origen cambia, se anula y nace otro (`CashLedger`).
 */
class FinancialTransaction extends Model
{
    // Lo que entra o sale solo, desde la operación
    public const CUSTOMER_PAYMENT = 'customer_payment'; // pago digital de un cliente (§75, §76)
    public const AGENCY_CASH = 'agency_cash';            // efectivo que cobró la agencia (§42)
    public const COMPANY_CHANGE = 'company_change';      // vuelto que dio la empresa
    // Lo que se registra a mano (CashMovement, §37 a §41, §83)
    public const OWNER_CONTRIBUTION = 'owner_contribution';
    public const OWNER_WITHDRAWAL = 'owner_withdrawal';
    public const TRANSFER_OUT = 'transfer_out';
    public const TRANSFER_IN = 'transfer_in';
    public const TRANSFER_FEE = 'transfer_fee';
    public const EXCHANGE_OUT = 'exchange_out';
    public const EXCHANGE_IN = 'exchange_in';
    public const EXCHANGE_FEE = 'exchange_fee';
    public const ADJUSTMENT = 'adjustment';
    // Pagos (entrega 3c y 3d)
    public const PAYABLE_PAYMENT = 'payable_payment';           // pago de una deuda: proveedor, agencia… (§17, §102)
    public const TRIP_PAYMENT = 'trip_payment';                 // lo pagado de un viaje de traslado (§13)
    public const AGENCY_SETTLEMENT_OUT = 'agency_settlement_out'; // liquidación de efectivo: sale de la agencia (§43)
    public const AGENCY_SETTLEMENT_IN = 'agency_settlement_in';   // …y entra a la cuenta donde se guarda
    // Los demás pagos (entrega 3d)
    public const EXPENSE_PAYMENT = 'expense_payment';             // gasto manual pagado (§28)
    public const RECURRING_PAYMENT = 'recurring_payment';         // pago de un vencimiento de un gasto recurrente (§28)
    public const CUSTOMER_REFUND = 'customer_refund';             // reembolso a un cliente (§77)
    public const RECEIVABLE_COLLECTION = 'receivable_collection'; // cobro de una cuenta por cobrar (§45)

    public const KIND_LABEL = [
        self::CUSTOMER_PAYMENT => 'Pago de cliente',
        self::AGENCY_CASH => 'Efectivo cobrado por la agencia',
        self::COMPANY_CHANGE => 'Vuelto de la empresa',
        self::OWNER_CONTRIBUTION => 'Aporte del propietario',
        self::OWNER_WITHDRAWAL => 'Retiro del propietario',
        self::TRANSFER_OUT => 'Transferencia (sale)',
        self::TRANSFER_IN => 'Transferencia (entra)',
        self::TRANSFER_FEE => 'Comisión de transferencia',
        self::EXCHANGE_OUT => 'Cambio de moneda (sale)',
        self::EXCHANGE_IN => 'Cambio de moneda (entra)',
        self::EXCHANGE_FEE => 'Comisión del cambio',
        self::ADJUSTMENT => 'Ajuste de saldo',
        self::PAYABLE_PAYMENT => 'Pago de una deuda',
        self::TRIP_PAYMENT => 'Viaje de traslado',
        self::AGENCY_SETTLEMENT_OUT => 'Liquidación de efectivo (sale de la agencia)',
        self::AGENCY_SETTLEMENT_IN => 'Liquidación de efectivo (entra)',
        self::EXPENSE_PAYMENT => 'Gasto pagado',
        self::RECURRING_PAYMENT => 'Pago de gasto recurrente',
        self::CUSTOMER_REFUND => 'Reembolso a cliente',
        self::RECEIVABLE_COLLECTION => 'Cobro de una cuenta por cobrar',
    ];

    /** Movimientos entre cuentas de la empresa: no son entradas ni salidas del negocio (§39, §41). */
    public const INTERNAL = [self::TRANSFER_OUT, self::TRANSFER_IN, self::EXCHANGE_OUT, self::EXCHANGE_IN, self::AGENCY_SETTLEMENT_OUT, self::AGENCY_SETTLEMENT_IN];

    // Estados (§75)
    public const CONFIRMED = 'confirmed';
    public const PENDING = 'pending';     // por conciliar: cuenta en el saldo y se ve aparte
    public const NOT_FOUND = 'not_found'; // no encontrado en el extracto: no cuenta hasta que se resuelva

    public const STATUS_LABEL = [self::CONFIRMED => 'Conciliado', self::PENDING => 'Por conciliar', self::NOT_FOUND => 'No encontrado'];

    /** Los que cuentan en el saldo. */
    public const COUNTED = [self::CONFIRMED, self::PENDING];

    protected $fillable = [
        'financial_account_id', 'occurred_on', 'amount', 'currency', 'rate', 'amount_usd', 'kind', 'status', 'source_type', 'source_id',
        'leg', 'order_id', 'description', 'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected $casts = [
        'occurred_on' => 'date:Y-m-d',
        'amount' => 'float',
        'rate' => 'float',
        'amount_usd' => 'float',
        'voided_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('voided_at');
    }

    public function scopeCounted($query)
    {
        return $query->whereNull('voided_at')->whereIn('status', self::COUNTED);
    }
}
