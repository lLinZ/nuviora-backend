<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un producto que vende un proveedor (Módulo 5 de Fran, §17–20): desde qué ciudad, a qué precio esperado, en cuántos
 * días despacha y a qué almacén suele llegar. Un producto puede tener varios; uno puede ser el preferido. El proveedor no
 * entra al sistema y no se le hacen pedidos automáticos (§18).
 */
class SupplierProduct extends Model
{
    protected $fillable = [
        'supplier_id', 'product_id', 'city_id', 'expected_unit_cost_usd', 'lead_time_days', 'receiving_warehouse_id', 'is_active', 'is_preferred', 'notes',
    ];

    protected $casts = [
        'expected_unit_cost_usd' => 'float',
        'lead_time_days' => 'float',
        'is_active' => 'boolean',
        'is_preferred' => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function receivingWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'receiving_warehouse_id');
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->supplier?->name,
            'product_id' => $this->product_id,
            'product' => $this->product ? ($this->product->showable_name ?: $this->product->title) : null,
            'city_id' => $this->city_id,
            'city' => $this->city?->name,
            'expected_unit_cost_usd' => $this->expected_unit_cost_usd,
            'lead_time_days' => $this->lead_time_days,
            'receiving_warehouse_id' => $this->receiving_warehouse_id,
            'receiving_warehouse' => $this->receivingWarehouse?->name,
            'is_active' => $this->is_active,
            'is_preferred' => $this->is_preferred,
            'notes' => $this->notes,
        ];
    }
}
