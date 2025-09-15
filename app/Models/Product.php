<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    //
    use HasFactory;
    public function orderProducts()
    {
        return $this->hasMany(OrderProducts::class);
    }
    protected $fillable = [
        'product_id',
        'title',
        'name',
        'price',
        'sku',
        'image',
    ];
}
