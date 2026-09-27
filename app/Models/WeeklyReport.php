<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Lo que escribe la Líder en su reporte semanal (spec §16.3). */
class WeeklyReport extends Model
{
    /** Campos que completa la Líder, con su título en el reporte. */
    public const FIELDS = [
        'problems' => 'Principales problemas encontrados',
        'actions' => 'Acciones tomadas',
        'coaching' => 'Coaching y capacitaciones realizadas',
        'recommendations' => 'Recomendaciones',
        'product_issues' => 'Problemas por producto o proceso',
        'decisions_needed' => 'Decisiones que necesita del administrador',
        'next_priorities' => 'Prioridades para la próxima semana',
    ];

    protected $fillable = [
        'sales_group_id', 'week_start', 'updated_by',
        'problems', 'actions', 'coaching', 'recommendations', 'product_issues', 'decisions_needed', 'next_priorities',
    ];

    protected $casts = ['week_start' => 'date:Y-m-d'];

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
