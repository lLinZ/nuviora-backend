<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderProduct extends Model // 👈 mejor singular
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_number',
        'title',
        'name',
        'price',
        'quantity',
        'image'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
