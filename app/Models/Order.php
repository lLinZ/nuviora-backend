<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    //
    use HasFactory;
    protected $fillable = [
        'order_id',
        'name',
        'current_total_price',
        'order_number',
        'processed_at',
        'currency',
        'client_id'
    ];
}
