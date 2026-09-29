<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stock de una variante en un almacén (tarea 4). inventories.quantity sigue siendo el total del producto
 * en ese almacén e incluye esto; la diferencia es stock "sin variante" (cargado sin decir la talla).
 */
class InventoryVariant extends Model
{
    protected $fillable = [
        'warehouse_id',
        'product_id',
        'variant_id',
        'quantity',
        'defective_stock',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'defective_stock' => 'integer',
    ];

    protected $appends = ['useful_stock'];

    /** Lo que se puede vender de esta variante: sin las piezas defectuosas. */
    public function getUsefulStockAttribute(): int
    {
        return max(0, $this->quantity - $this->defective_stock);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
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
