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
        'change_approved_by',
        'change_approved_at',
        'change_approved_signature',
    ];

    protected $casts = [
        'change_payment_details' => 'array',
        'change_approved_at' => 'datetime',
    ];

    public function approver()
    {
        return $this->belongsTo(User::class, 'change_approved_by');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
