<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReceipt extends Model
{
    //
    protected $fillable = ['order_id', 'path', 'original_name'];
    
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
