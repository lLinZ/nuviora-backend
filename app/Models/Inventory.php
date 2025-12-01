<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = ['warehouse_id', 'product_id', 'quantity'];
    
    /**
     * Get the warehouse this inventory belongs to
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
    
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
