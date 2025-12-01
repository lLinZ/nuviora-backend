<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_type_id',
        'code',
        'name',
        'description',
        'location',
        'is_active',
        'is_main',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_main' => 'boolean',
    ];

    /**
     * Get the warehouse type
     */
    public function warehouseType()
    {
        return $this->belongsTo(WarehouseType::class);
    }

    /**
     * Get all inventories in this warehouse
     */
    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    /**
     * Get movements from this warehouse
     */
    public function movementsFrom()
    {
        return $this->hasMany(InventoryMovement::class, 'from_warehouse_id');
    }

    /**
     * Get movements to this warehouse
     */
    public function movementsTo()
    {
        return $this->hasMany(InventoryMovement::class, 'to_warehouse_id');
    }

    /**
     * Scope to get only active warehouses
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get the main warehouse
     */
    public function scopeMain($query)
    {
        return $query->where('is_main', true);
    }

    /**
     * Get stock for a specific product in this warehouse
     */
    public function getProductStock($productId)
    {
        $inventory = $this->inventories()->where('product_id', $productId)->first();
        return $inventory ? $inventory->quantity : 0;
    }
}
