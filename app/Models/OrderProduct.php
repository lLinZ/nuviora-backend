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
        'showable_name',
        'price',
        'quantity',
        'image',
        'is_upsell',
        'upsell_user_id'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function upsellUser()
    {
        return $this->belongsTo(User::class, 'upsell_user_id');
    }
}
