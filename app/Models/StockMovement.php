<?php

// app/Models/StockMovement.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockMovement extends Model
{
    use HasFactory;
    protected $fillable = ['product_id', 'user_id', 'type', 'quantity', 'reason', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
