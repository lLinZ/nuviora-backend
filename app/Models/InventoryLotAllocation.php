<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Unidades que un movimiento sacó (quantity < 0) o puso (> 0) en una capa. Sin capa: salieron sin lote. */
class InventoryLotAllocation extends Model
{
    protected $fillable = [
        'inventory_movement_id', 'layer_id', 'warehouse_id', 'product_id', 'variant_id', 'quantity', 'is_opening',
        'reference_type', 'reference_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'is_opening' => 'boolean',
    ];

    public function layer(): BelongsTo
    {
        return $this->belongsTo(InventoryLotLayer::class, 'layer_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }
}
