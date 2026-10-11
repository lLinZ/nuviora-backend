<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una regla de señal de Meta Ads, configurable sin tocar código (Módulo 2, §39 y §40). Solo avisa (§36). */
class MetaAlertRule extends Model
{
    public const TYPES = ['spend_no_purchases', 'limited_sample', 'trend'];
    /** CTR y CPC solo del enlace (respuesta de Fran del 2026-10-09, pregunta 2). */
    public const TREND_METRICS = ['cpa', 'cpm', 'link_ctr', 'link_cpc', 'frequency', 'hook_rate', 'hold_rate', 'lpv_rate', 'conversion_rate', 'spend', 'purchases'];
    public const SEVERITIES = ['info', 'alert', 'critical'];

    /** threshold: × Target CPA (spend_no_purchases), compras mínimas (limited_sample) o % (trend). min_spend: USD (limited_sample). */
    protected $fillable = ['type', 'metric', 'direction', 'threshold', 'min_spend', 'severity', 'is_active', 'updated_by'];

    protected $casts = ['threshold' => 'float', 'min_spend' => 'float', 'is_active' => 'boolean'];
}
