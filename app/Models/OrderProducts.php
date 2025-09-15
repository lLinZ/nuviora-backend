<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderProducts extends Model
{
    use HasFactory;
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    // line_items
    protected $fillable = [
        'order_id',
        'product_id',
        'product_number', //product_id
        'title',
        'name',
        'price',
        'quantity',
        'image'
    ];
}
