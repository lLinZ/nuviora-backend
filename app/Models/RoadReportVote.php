<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** "Sigue activo" o "ya despejado" sobre una incidencia vial (§15), con quién y cuándo. */
class RoadReportVote extends Model
{
    public const STILL = 'activo';
    public const CLEARED = 'despejado';

    public $timestamps = false;

    protected $fillable = ['road_report_id', 'user_id', 'vote', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
