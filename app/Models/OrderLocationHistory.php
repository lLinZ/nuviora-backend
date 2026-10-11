<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un cambio de la ubicación del cliente: lo de antes y lo de después, sin sobrescribir (Módulo 3 de Fran, §19). */
class OrderLocationHistory extends Model
{
    protected $table = 'order_location_history';

    public $timestamps = false;

    protected $fillable = [
        'order_id', 'previous_url', 'previous_lat', 'previous_lng', 'new_url', 'new_lat', 'new_lng', 'new_status',
        'changed_by', 'reason', 'after_attempt', 'changed_at',
    ];

    protected $casts = [
        'previous_lat' => 'float',
        'previous_lng' => 'float',
        'new_lat' => 'float',
        'new_lng' => 'float',
        'after_attempt' => 'boolean',
        'changed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
