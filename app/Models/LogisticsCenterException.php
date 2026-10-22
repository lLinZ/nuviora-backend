<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un día distinto del horario normal del centro: cerrado, o con otro horario (§4: "excepciones por fecha"). */
class LogisticsCenterException extends Model
{
    protected $fillable = ['center_id', 'date', 'is_closed', 'opens_at', 'closes_at', 'note', 'created_by'];

    protected $casts = [
        'date' => 'date',
        'is_closed' => 'boolean',
    ];

    public function center()
    {
        return $this->belongsTo(LogisticsCenter::class, 'center_id');
    }
}
