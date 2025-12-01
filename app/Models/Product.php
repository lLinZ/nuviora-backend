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
    protected $fillable = [
        'product_id',
        'title',
        'name',
        'price',
        'sku',
        'image',
    ];
}
