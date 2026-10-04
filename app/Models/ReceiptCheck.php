<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Revisión con IA de un comprobante de pago (Fran, 2026-10-03). Ver App\Services\Payments\ReceiptChecker.
 */
class ReceiptCheck extends Model
{
    public const PENDING = 'pending';
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const FAIL = 'fail';
    public const UNREADABLE = 'unreadable';
    public const ERROR = 'error';

    protected $fillable = [
        'payment_receipt_id', 'order_id', 'status', 'kind', 'extracted', 'issues', 'reference', 'amount', 'currency',
        'model', 'response_id', 'input_tokens', 'output_tokens', 'error', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'extracted' => 'array',
        'issues' => 'array',
        'amount' => 'float',
        'reviewed_at' => 'datetime',
    ];

    protected $hidden = ['response_id', 'input_tokens', 'output_tokens'];

    public function receipt()
    {
        return $this->belongsTo(PaymentReceipt::class, 'payment_receipt_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Fran lo revisó y lo dio por bueno. */
    public function isApproved(): bool
    {
        return $this->reviewed_at !== null;
    }
}
