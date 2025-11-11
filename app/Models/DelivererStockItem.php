<?php

// app/Models/DelivererStockItem.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DelivererStockItem extends Model
{
    use HasFactory;

    protected $fillable = ['deliverer_stock_id', 'product_id', 'qty_assigned', 'qty_delivered', 'qty_returned'];

    public function stock()
    {
        return $this->belongsTo(DelivererStock::class, 'deliverer_stock_id');
    }
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getQtyOnHandAttribute(): int
    {
        // disponible = asignado - entregado - devuelto
        return max(0, ($this->qty_assigned - $this->qty_delivered - $this->qty_returned));
    }
}
