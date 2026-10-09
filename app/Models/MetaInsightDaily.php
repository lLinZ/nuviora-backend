<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fila del histórico de Meta (Módulo 2, §24): un día de una campaña, un ad set o un anuncio, con los valores base
 * (§50). Es única por fecha + nivel + ID de Meta: volver a sincronizar ese día la reemplaza (§25).
 */
class MetaInsightDaily extends Model
{
    protected $table = 'meta_insights_daily';

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'actions' => 'array',
        'synced_at' => 'datetime',
    ];
}
