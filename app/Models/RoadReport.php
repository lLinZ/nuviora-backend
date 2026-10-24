<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una incidencia vial compartida (§15): la reporta un rider y caduca sola si nadie confirma que sigue. */
class RoadReport extends Model
{
    /**
     * §15: "tráfico detenido, accidente, vía cerrada, obra, inundación, manifestación, control/operativo que está generando
     * demora u otro obstáculo". El control se registra solo como demora: no se usa para evadir a la autoridad.
     */
    public const TYPES = [
        'trafico_detenido' => 'Tráfico detenido',
        'accidente' => 'Accidente en la vía',
        'via_cerrada' => 'Vía cerrada',
        'obra' => 'Obra',
        'inundacion' => 'Inundación',
        'manifestacion' => 'Manifestación',
        'control_demora' => 'Control u operativo que genera demora',
        'otro' => 'Otro obstáculo',
    ];

    protected $fillable = ['center_id', 'reported_by', 'type', 'comment', 'lat', 'lng', 'reported_at', 'expires_at', 'cleared_at', 'cleared_by'];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'reported_at' => 'datetime',
        'expires_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function center()
    {
        return $this->belongsTo(LogisticsCenter::class);
    }

    public function votes()
    {
        return $this->hasMany(RoadReportVote::class)->orderBy('id');
    }

    public function isActive(): bool
    {
        return $this->cleared_at === null && $this->expires_at->isFuture();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
