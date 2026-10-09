<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El estado actual de un rango fijo (Módulo 2, §57 y §27): los valores de Meta para "últimos 7 días", "este mes"… de
 * una campaña, un ad set o un anuncio. El alcance y la frecuencia de un rango no se pueden sumar día por día, así que
 * se guardan como los calcula Meta.
 */
class MetaInsightCurrent extends Model
{
    protected $table = 'meta_insights_current';

    protected $guarded = ['id'];

    protected $casts = [
        'date_start' => 'date:Y-m-d',
        'date_stop' => 'date:Y-m-d',
        'actions' => 'array',
        'synced_at' => 'datetime',
    ];
}
