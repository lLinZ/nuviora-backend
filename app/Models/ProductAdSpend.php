<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductAdSpend extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'date',
        'amount',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
