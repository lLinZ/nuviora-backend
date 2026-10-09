<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un anuncio de Meta (Módulo 2, §9), con su creativo de Meta y el creative_tracking_id interno (§32): detectado por la
 * nomenclatura o corregido a mano. Hereda producto y ciudad de su campaña (§12).
 */
class MetaAd extends Model
{
    protected $fillable = [
        'meta_ad_account_id', 'meta_campaign_id', 'meta_adset_id', 'campaign_meta_id', 'adset_meta_id', 'meta_id', 'name',
        'status', 'effective_status', 'created_time', 'meta_creative_id', 'creative_type', 'video_id', 'image_hash',
        'thumbnail_url', 'meta_creative_ref_id', 'creative_tracking_id', 'tracking_id_source', 'last_known_state',
        'last_seen_at', 'missing_since', 'meta',
    ];

    protected $casts = [
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

    public function adset()
    {
        return $this->belongsTo(MetaAdset::class, 'meta_adset_id');
    }

    public function creative()
    {
        return $this->belongsTo(MetaCreative::class, 'meta_creative_ref_id');
    }
}
