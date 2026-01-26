<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderChangeExtra extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'change_payment_details',
        'change_receipt',
    ];

    protected $casts = [
        'change_payment_details' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
