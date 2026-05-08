<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'quantity_ordered',
        'quantity_received',
        'unit_cost_usd',
        'unit_cost_ves',
        'notes',
    ];

    protected $casts = [
        'quantity_ordered'  => 'integer',
        'quantity_received' => 'integer',
        'unit_cost_usd'     => 'float',
        'unit_cost_ves'     => 'float',
    ];

    protected $appends = ['pending_quantity', 'subtotal_usd'];

    /**
     * Parent purchase order
     */
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Product
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * How many units are still pending to receive
     */
    public function getPendingQuantityAttribute(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    /**
     * Subtotal in USD for this line item
     */
    public function getSubtotalUsdAttribute(): float
    {
        return round($this->unit_cost_usd * $this->quantity_ordered, 2);
    }
}
