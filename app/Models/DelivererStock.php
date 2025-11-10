<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelivererStock extends Model
{
    protected $fillable = ['date', 'deliverer_id', 'product_id', 'qty_assigned', 'qty_returned'];
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function deliverer()
    {
        return $this->belongsTo(User::class, 'deliverer_id');
    }
}
