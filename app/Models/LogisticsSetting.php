<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una versión de los parámetros de logística (§31), con su fecha de vigencia (§33: "las reglas configurables deben tener
 * versión/fecha de vigencia"). No se edita: un cambio es una versión nueva. Ver App\Services\Logistics\LogisticsSettings.
 */
class LogisticsSetting extends Model
{
    protected $fillable = ['center_id', 'values', 'valid_from', 'created_by'];

    protected $casts = [
        'values' => 'array',
        'valid_from' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
