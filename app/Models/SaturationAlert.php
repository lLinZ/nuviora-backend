<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una alerta de saturación (ver la migración y SaturationMonitor). */
class SaturationAlert extends Model
{
    protected $fillable = ['seller_id', 'sales_group_id', 'load', 'group_average', 'over_pct', 'cleared_at'];

    protected $casts = [
        'load' => 'integer',
        'group_average' => 'float',
        'over_pct' => 'float',
        'cleared_at' => 'datetime',
    ];

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function group()
    {
        return $this->belongsTo(SalesGroup::class, 'sales_group_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('cleared_at');
    }
}
