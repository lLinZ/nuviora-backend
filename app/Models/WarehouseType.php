<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_physical',
    ];

    protected $casts = [
        'is_physical' => 'boolean',
    ];

    /**
     * Get all warehouses of this type
     */
    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }
}
