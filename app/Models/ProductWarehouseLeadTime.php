<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductWarehouseLeadTime extends Model
{
    protected $table = 'product_warehouse_lead_times';

    // Composite PK — disable auto-increment
    public $incrementing = false;

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'lead_time_days',
    ];

    protected $casts = [
        'lead_time_days' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
