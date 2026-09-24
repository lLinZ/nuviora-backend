<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class PaymentReceipt extends Model
{
    //
    protected $fillable = ['order_id', 'path', 'original_name'];

    // 🔒 Enlace firmado para ver el comprobante sin sesión (vence en 12 h)
    protected $appends = ['url'];

    public function getUrlAttribute()
    {
        return URL::temporarySignedRoute('receipts.show', now()->addHours(12), ['receipt' => $this->id]);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
