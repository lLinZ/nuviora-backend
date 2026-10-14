<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lote de inventario (Módulo 4 de Fran, §9, §12, R5, R7). Lo mueve App\Services\Finance\LotLedger. */
class InventoryLot extends Model
{
    public const KIND_INITIAL = 'initial';
    public const KIND_PURCHASE = 'purchase';
    public const KIND_PENDING_COST = 'pending_cost';
    public const KIND_RECOVERED = 'recovered';
    /** Corrección de conteo hacia arriba (Fran, 2026-10-14): al costo del lote más reciente del producto. */
    public const KIND_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'product_id', 'variant_id', 'kind', 'purchase_id', 'source_movement_id', 'initial_quantity', 'unit_cost_usd', 'cost_source',
        'received_at', 'notes',
    ];

    protected $casts = [
        'initial_quantity' => 'integer',
        'unit_cost_usd' => 'float',
        'received_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchase::class, 'purchase_id');
    }

    public function layers(): HasMany
    {
        return $this->hasMany(InventoryLotLayer::class, 'lot_id');
    }
}
