<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un ad set de Meta (Módulo 2, §9), con su configuración de atribución. Hereda producto y ciudad de su campaña (§12). */
class MetaAdset extends Model
{
    protected $table = 'meta_adsets';

    protected $fillable = [
        'meta_ad_account_id', 'meta_campaign_id', 'campaign_meta_id', 'meta_id', 'name', 'status', 'effective_status',
        'optimization_goal', 'attribution_spec', 'created_time', 'last_known_state', 'last_seen_at', 'missing_since', 'meta',
    ];

    protected $casts = [
        'attribution_spec' => 'array',
        'created_time' => 'datetime',
        'last_seen_at' => 'datetime',
        'missing_since' => 'datetime',
        'meta' => 'array',
    ];

    public function campaign()
    {
        return $this->belongsTo(MetaCampaign::class, 'meta_campaign_id');
    }

    public function account()
    {
        return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id');
    }
}
