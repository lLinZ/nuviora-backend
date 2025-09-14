<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderProducts extends Model
{
    use HasFactory;

    // line_items
    protected $fillable = [
        'order_id',
        'product_id',
        'product_number', //product_id
        'title',
        'name',
        'price',
    ];
}
