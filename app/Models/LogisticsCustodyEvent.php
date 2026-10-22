<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un cambio de manos del producto de una orden (§11, §14.3, §16.2.2): de dónde a dónde, qué, cuándo y quién lo confirmó. */
class LogisticsCustodyEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['logistics_order_id', 'order_id', 'from_type', 'from_id', 'to_type', 'to_id', 'items', 'lat', 'lng', 'confirmed_by', 'created_at'];

    protected $casts = [
        'items' => 'array',
        'lat' => 'float',
        'lng' => 'float',
        'created_at' => 'datetime',
    ];
}
