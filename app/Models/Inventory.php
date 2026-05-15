<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = [
        'warehouse_id',
        'product_id',
        'quantity',
        'reserved_stock',
        'defective_stock',
        'blocked_stock',
        'sizes_stock',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($inventory) {
            if (isset($inventory->sizes_stock) && is_array($inventory->sizes_stock)) {
                $inventory->quantity = array_sum($inventory->sizes_stock);
            }
        });
    }

    protected $casts = [
        'quantity'        => 'integer',
        'reserved_stock'  => 'integer',
        'defective_stock' => 'integer',
        'blocked_stock'   => 'integer',
        'sizes_stock'     => 'array',
    ];

    protected $appends = ['useful_stock'];

    /**
     * Stock Útil = Físico - Reservado - Defectuoso - Bloqueado
     * Regla de Oro #1 del plan SCM
     */
    public function getUsefulStockAttribute(): int
    {
        return max(0, $this->quantity - $this->reserved_stock - $this->defective_stock - $this->blocked_stock);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
