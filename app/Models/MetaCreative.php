<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un creativo propio, identificado por el creative_tracking_id (Módulo 2, §32). Puede estar en varios anuncios, ad sets
 * y cuentas (§33). Su estado (Testing, Winner, Fatigued, Loser, Archived…) se asigna a mano (§41).
 */
class MetaCreative extends Model
{
    public const STATUSES = ['testing', 'winner', 'fatigued', 'loser', 'archived'];

    protected $fillable = ['tracking_id', 'type', 'status', 'status_changed_by', 'status_changed_at'];

    protected $casts = ['status_changed_at' => 'datetime'];

    public function ads()
    {
        return $this->hasMany(MetaAd::class, 'meta_creative_ref_id');
    }
}
