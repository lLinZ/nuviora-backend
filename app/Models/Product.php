<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    //
    use HasFactory;
    
    protected static function booted()
    {
        static::updated(function ($product) {
            if ($product->wasChanged('showable_name')) {
                $product->orderProducts()->update([
                    'showable_name' => $product->showable_name
                ]);
            }
        });
    }

    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Stock disponible en inventario general
     */
    public function getAvailableStockAttribute(): int
    {
        // Entradas (IN, RETURN) y salidas (OUT, ASSIGN, SALE)
        $in = $this->stockMovements()
            ->whereIn('type', ['IN', 'RETURN'])
            ->sum('quantity');

        $out = $this->stockMovements()
            ->whereIn('type', ['OUT', 'ASSIGN', 'SALE'])
            ->sum('quantity');

        return $in - $out;
    }
    public function adSpends()
    {
        return $this->hasMany(ProductAdSpend::class);
    }

    protected $fillable = [
        'product_id',
        'variant_id',
        'title',
        'name',
        'showable_name',
        'price',
        'cost_usd',
        'sku',
        'image',
    ];
}
