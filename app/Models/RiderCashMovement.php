<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un movimiento del efectivo bajo custodia de un rider (§17, §26): un cobro o una entrega al operador. */
class RiderCashMovement extends Model
{
    public const COLLECTION = 'cobro';
    public const HANDOVER = 'entrega';

    public $timestamps = false;

    protected $fillable = [
        'rider_id', 'center_id', 'type', 'logistics_order_id', 'order_id', 'usd', 'ves', 'total_usd',
        'expected_usd', 'expected_ves', 'received_usd', 'received_ves', 'has_difference', 'actor_id', 'note', 'created_at',
    ];

    protected $casts = [
        'usd' => 'float',
        'ves' => 'float',
        'total_usd' => 'float',
        'expected_usd' => 'float',
        'expected_ves' => 'float',
        'received_usd' => 'float',
        'received_ves' => 'float',
        'has_difference' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
