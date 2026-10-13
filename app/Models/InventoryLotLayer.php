<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parte de un lote en un almacén y un estado (available o review), §12. */
class InventoryLotLayer extends Model
{
    public const AVAILABLE = 'available';
    public const REVIEW = 'review';

    protected $fillable = [
        'lot_id', 'warehouse_id', 'product_id', 'variant_id', 'state', 'quantity', 'opening_quantity',
        'landed_cost_per_unit', 'parent_layer_id', 'created_by_movement_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'opening_quantity' => 'integer',
        'landed_cost_per_unit' => 'float',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
