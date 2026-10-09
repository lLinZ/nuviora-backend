<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una campaña de Meta (Módulo 2, §9). Se identifica por su ID de Meta: un cambio de nombre actualiza este mismo
 * registro (§10). Pertenece a un solo producto y a una sola ciudad, que elige el Admin (§11); sus ad sets y anuncios los
 * heredan (§12). Sin clasificar, sus datos se guardan pero no entran en los totales por producto y ciudad (§13).
 */
class MetaCampaign extends Model
{
    protected $fillable = [
        'meta_ad_account_id', 'meta_id', 'name', 'status', 'effective_status', 'objective', 'created_time', 'start_time',
        'stop_time', 'product_id', 'city_id', 'classified_by', 'classified_at', 'last_known_state', 'last_seen_at',
        'missing_since', 'meta',
    ];

    protected $casts = [
        'created_time' => 'datetime',
        'start_time' => 'datetime',
        'stop_time' => 'datetime',
        'classified_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'missing_since' => 'datetime',
        'meta' => 'array',
    ];

    public function account()
    {
        return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function classifier()
    {
        return $this->belongsTo(User::class, 'classified_by');
    }

    public function adsets()
    {
        return $this->hasMany(MetaAdset::class);
    }

    public function ads()
    {
        return $this->hasMany(MetaAd::class);
    }

    public function isClassified(): bool
    {
        return $this->product_id !== null && $this->city_id !== null;
    }
}
