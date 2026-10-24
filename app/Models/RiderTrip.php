<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un tramo pagado al rider que no nace de una entrega: la ida cuando el cliente no recibió (§18.4, R7). */
class RiderTrip extends Model
{
    public const FAILED_LEG = 'ida_fallida';

    public $timestamps = false;

    protected $fillable = ['rider_id', 'center_id', 'logistics_order_id', 'order_id', 'concept', 'trip_date', 'meters', 'amount_usd', 'basis', 'created_at'];

    protected $casts = [
        'trip_date' => 'date',
        'amount_usd' => 'float',
        'basis' => 'array',
        'created_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
