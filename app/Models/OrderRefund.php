<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Reembolso de una orden entregada (tarea 3b, Fran §7). Ver App\Http\Controllers\OrderRefundController. */
class OrderRefund extends Model
{
    public const METHODS = ['Pago móvil', 'Transferencia', 'Zelle', 'Binance', 'Efectivo', 'Otro'];

    protected $fillable = ['order_id', 'amount_usd', 'method', 'refunded_at', 'notes', 'receipt_path', 'user_id'];

    protected $casts = [
        'amount_usd' => 'float',
        'refunded_at' => 'date:Y-m-d',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_name' => $this->order?->name,
            'amount_usd' => $this->amount_usd,
            'method' => $this->method,
            'refunded_at' => $this->refunded_at?->toDateString(),
            'notes' => $this->notes,
            'has_receipt' => (bool) $this->receipt_path,
            'user_name' => $this->user?->names,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
