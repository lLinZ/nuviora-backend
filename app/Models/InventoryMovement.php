<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'variant_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'quantity',
        'size',
        'movement_type',
        'reference_type',
        'reference_id',
        'user_id',
        'notes',
        'status',
        'details',
        'trip_id',
        'reason',
    ];

    /**
     * Tipos de ajuste y de salida interna (Módulo 5 de Fran, §94, R4). Los de conteo fijan la cantidad, como hasta ahora
     * (Fran, 2026-10-14). Muestra, regalo y uso interno son salidas cuyo costo cuenta como gasto, igual que la merma.
     * Dañado y perdido no son un ajuste: van a "en revisión".
     */
    public const COUNT_REASONS = [
        'count_error' => 'Error de conteo',
        'admin_correction' => 'Corrección administrativa',
        'other' => 'Otro',
    ];

    public const WRITE_OFF_REASONS = [
        'sample' => 'Muestra',
        'gift' => 'Regalo',
        'internal_use' => 'Uso interno',
    ];

    /** Referencia de las salidas por muestra, regalo o uso interno */
    public const WRITE_OFF_REFERENCE = 'InventoryWriteOff';

    protected $casts = [
        'quantity' => 'integer',
        'details' => 'array',
    ];

    /**
     * Get the product
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** El viaje de traslado que lo llevó (Módulo 4 de Fran, §13, R8) */
    public function trip()
    {
        return $this->belongsTo(InventoryTransferTrip::class, 'trip_id');
    }

    /**
     * Get the source warehouse
     */
    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /**
     * Get the destination warehouse
     */
    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    /**
     * Get the user who made the movement
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the reference (polymorphic)
     */
    public function reference()
    {
        return $this->morphTo();
    }

    /**
     * Scope for transfer movements
     */
    public function scopeTransfers($query)
    {
        return $query->where('movement_type', 'transfer');
    }

    /**
     * Scope for incoming movements
     */
    public function scopeIncoming($query)
    {
        return $query->where('movement_type', 'in');
    }

    /**
     * Scope for outgoing movements
     */
    public function scopeOutgoing($query)
    {
        return $query->where('movement_type', 'out');
    }

    /**
     * Scope for adjustments
     */
    public function scopeAdjustments($query)
    {
        return $query->where('movement_type', 'adjustment');
    }

    /**
     * Scope for a specific product
     */
    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope for a specific warehouse (from or to)
     */
    public function scopeForWarehouse($query, $warehouseId)
    {
        return $query->where(function ($q) use ($warehouseId) {
            $q->where('from_warehouse_id', $warehouseId)
              ->orWhere('to_warehouse_id', $warehouseId);
        });
    }
}
