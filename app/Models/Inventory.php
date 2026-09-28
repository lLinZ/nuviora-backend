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

        // Si solo se cambió el desglose por tallas, el total pasa a ser su suma. Si se cambió el total
        // (una venta sin talla, un traslado), se respeta: antes se recalculaba siempre y esos cambios
        // se perdían sin aviso (tarea 3, punto 5).
        static::saving(function ($inventory) {
            if ($inventory->isDirty('sizes_stock') && !$inventory->isDirty('quantity')
                && is_array($inventory->sizes_stock) && $inventory->sizes_stock) {
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
