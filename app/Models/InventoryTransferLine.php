<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una línea de un traslado (Módulo 5, §92): producto, variante (si la tiene) y cantidad, con los movimientos de cada paso. */
class InventoryTransferLine extends Model
{
    protected $fillable = ['transfer_id', 'product_id', 'variant_id', 'quantity', 'dispatch_movement_id', 'receive_movement_id'];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
