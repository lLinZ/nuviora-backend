<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una regla de señal de Meta Ads, configurable sin tocar código (Módulo 2, §39 y §40). Solo avisa (§36). */
class MetaAlertRule extends Model
{
    public const TYPES = ['spend_no_purchases', 'limited_sample', 'trend'];
    public const TREND_METRICS = ['cpa', 'cpm', 'ctr', 'link_ctr', 'cpc', 'frequency', 'hook_rate', 'hold_rate', 'lpv_rate', 'conversion_rate', 'spend', 'purchases'];
    public const SEVERITIES = ['info', 'alert', 'critical'];

    protected $fillable = ['type', 'metric', 'direction', 'threshold', 'severity', 'is_active', 'updated_by'];

    protected $casts = ['threshold' => 'float', 'is_active' => 'boolean'];
}
