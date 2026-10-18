<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasFactory;

    /** Almacén virtual de lo que va en camino entre almacenes (Módulo 5, §15). Inactivo y sin usuario: nunca recibe órdenes. */
    public const TRANSIT_CODE = 'TRANSIT';

    private static ?int $transitId = null;

    protected $fillable = [
        'warehouse_type_id',
        'user_id',
        'city_id',
        'code',
        'name',
        'description',
        'location',
        'maps_url',   // Módulo 3, §6: link de Maps y coordenadas del almacén (origen de las carreras)
        'latitude',
        'longitude',
        'is_active',
        'is_main',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_main' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    /** Tiene coordenadas para calcular carreras (Módulo 3, §6). */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Get the warehouse type
     */
    public function warehouseType()
    {
        return $this->belongsTo(WarehouseType::class);
    }

    /**
     * Get the user linked to this warehouse (repartidor)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
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

    /** El id del almacén "En tránsito" (lo crea la migración del Módulo 5). */
    public static function transitId(): int
    {
        return self::$transitId ??= (int) self::where('code', self::TRANSIT_CODE)->value('id')
            ?: throw new \RuntimeException('Falta el almacén "En tránsito": corre las migraciones.');
    }

    public function isTransit(): bool
    {
        return $this->code === self::TRANSIT_CODE;
    }

    /** Los almacenes de verdad: sin el virtual de tránsito. */
    public function scopePhysical($query)
    {
        return $query->where('code', '!=', self::TRANSIT_CODE);
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
