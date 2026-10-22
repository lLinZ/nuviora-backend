<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Centro logístico de una ciudad (logística propia, §2): el almacén físico desde el que salen sus órdenes, con su
 * horario por día, sus excepciones por fecha y su cutoff (§4, §23). Una ciudad sin centro activo sigue como hoy, con
 * sus agencias (Fran, audio del 21/10: "solo Carabobo por el momento").
 */
class LogisticsCenter extends Model
{
    protected $fillable = ['name', 'city_id', 'warehouse_id', 'address', 'opening_hours', 'dispatch_cutoff', 'is_active', 'updated_by'];

    protected $casts = [
        'opening_hours' => 'array',
        'is_active' => 'boolean',
    ];

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function operators()
    {
        return $this->belongsToMany(User::class, 'logistics_center_operators', 'center_id', 'user_id')
            ->withPivot('coverage')->withTimestamps();
    }

    public function exceptions()
    {
        return $this->hasMany(LogisticsCenterException::class, 'center_id');
    }

    public function shifts()
    {
        return $this->hasMany(LogisticsShift::class, 'center_id');
    }

    /** El turno abierto: hay uno solo a la vez (§31; lo cuida Shifts::start). */
    public function activeShift()
    {
        return $this->hasOne(LogisticsShift::class, 'center_id')->whereNull('ended_at')->latest('started_at');
    }
}
