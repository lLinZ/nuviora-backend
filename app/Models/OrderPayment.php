<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'method',
        'amount',
        'rate',
        'reference',
        'usd_rate',
        'eur_rate',
        'binance_usd_rate',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
