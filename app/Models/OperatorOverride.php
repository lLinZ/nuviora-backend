<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** El cambio de un día en el calendario de operadores (§4.1): ese día trabaja otro, o nadie, y por qué. */
class OperatorOverride extends Model
{
    public const REASONS = [
        'sustitucion' => 'Sustitución',
        'vacaciones' => 'Vacaciones',
        'ausencia' => 'Ausencia',
        'cambio' => 'Cambio de último minuto',
    ];

    protected $fillable = ['center_id', 'date', 'user_id', 'reason', 'start_time', 'end_time', 'note', 'created_by'];

    protected $casts = ['date' => 'date'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
