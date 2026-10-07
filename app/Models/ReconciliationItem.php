<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un pago digital de una conciliación (documento de Fran del 2026-10-06, §3, §11 y §20). Guarda una copia de los datos
 * del pago, para que quede aunque después se borre el comprobante.
 */
class ReconciliationItem extends Model
{
    // Estados del §20 (más "pending": todavía no se subió el extracto)
    public const PENDING = 'pending';
    public const MATCHED = 'matched';           // ✅ Conciliado
    public const NOT_FOUND = 'not_found';       // ❌ Pago no encontrado
    public const REVIEW = 'review';             // 🟡 Requiere revisión
    public const CONFIRMED = 'confirmed';       // ✅ Confirmado manualmente
    public const NOT_RECEIVED = 'not_received'; // ❌ Confirmado como no recibido
    public const LINKED = 'linked';             // 🔗 Vinculado manualmente

    /** Los que ya no necesitan nada (§25: "todos los pagos conciliados o las incidencias resueltas manualmente"). */
    public const RESOLVED = [self::MATCHED, self::CONFIRMED, self::NOT_RECEIVED, self::LINKED];

    /** Los que decidió el administrador: buscar otra vez no los cambia. */
    public const MANUAL = [self::CONFIRMED, self::NOT_RECEIVED, self::LINKED];

    protected $fillable = [
        'reconciliation_day_id', 'receipt_check_id', 'payment_receipt_id', 'order_id', 'order_name', 'client_name',
        'method', 'currency', 'amount', 'reference', 'reference_norm', 'paid_on', 'company_account_id',
        'statement_source_id', 'status', 'bank_statement_row_id', 'match_note', 'candidates', 'resolved_by',
        'resolved_at', 'note',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_on' => 'date:Y-m-d',
        'candidates' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function day()
    {
        return $this->belongsTo(ReconciliationDay::class, 'reconciliation_day_id');
    }

    public function check()
    {
        return $this->belongsTo(ReceiptCheck::class, 'receipt_check_id');
    }

    public function receipt()
    {
        return $this->belongsTo(PaymentReceipt::class, 'payment_receipt_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function source()
    {
        return $this->belongsTo(StatementSource::class, 'statement_source_id');
    }

    public function row()
    {
        return $this->belongsTo(BankStatementRow::class, 'bank_statement_row_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
