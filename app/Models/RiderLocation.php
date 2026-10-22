<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una ubicación que mandó la app del rider (§6, §21.2). */
class RiderLocation extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'lat', 'lng', 'accuracy', 'speed', 'recorded_at', 'created_at'];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
