<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un paso en la historia logística de una orden: quién, cuándo, de qué estado a cuál y por qué (§26). */
class LogisticsOrderEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['logistics_order_id', 'actor_id', 'action', 'from_status', 'to_status', 'reason', 'data', 'created_at'];

    protected $casts = [
        'data' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
