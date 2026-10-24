<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** El patrón recurrente de un operador (§4.1): por ejemplo 12 días trabajados y 4 libres, en días corridos. */
class OperatorPattern extends Model
{
    protected $fillable = ['center_id', 'user_id', 'starts_on', 'days_on', 'days_off', 'start_time', 'end_time', 'is_active', 'created_by'];

    protected $casts = [
        'starts_on' => 'date',
        'days_on' => 'integer',
        'days_off' => 'integer',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** ¿Ese día cae en la parte trabajada del ciclo? Cuenta días corridos desde starts_on, sin reiniciarse los lunes. */
    public function worksOn(\Carbon\CarbonInterface $day): bool
    {
        $offset = (int) $this->starts_on->copy()->startOfDay()->diffInDays($day->copy()->startOfDay(), false);
        if ($offset < 0) {
            return false;
        }

        return $offset % ($this->days_on + $this->days_off) < $this->days_on;
    }
}
