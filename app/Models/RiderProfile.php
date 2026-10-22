<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El rider propio de un centro (logística propia, §6): su habilitación administrativa, sus franjas, su teléfono (para la
 * alarma de las rondas) y su última ubicación. El rider no decide cuándo participa: no hay botón "Disponible" (§6).
 */
class RiderProfile extends Model
{
    public const PERMISSION_ALWAYS = 'siempre';
    public const PERMISSION_IN_USE = 'en_uso';
    public const PERMISSION_DENIED = 'negado';

    protected $fillable = [
        'user_id', 'center_id', 'is_active', 'suspended_at', 'suspended_reason', 'schedule',
        'push_target_type', 'push_target', 'platform', 'app_version', 'notifications_enabled', 'location_permission',
        'device_reported_at', 'push_error', 'last_lat', 'last_lng', 'last_accuracy', 'last_location_at',
        'eta_center_seconds', 'eta_center_meters', 'eta_computed_at', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'suspended_at' => 'datetime',
        'schedule' => 'array',
        'notifications_enabled' => 'boolean',
        'device_reported_at' => 'datetime',
        'last_lat' => 'float',
        'last_lng' => 'float',
        'last_location_at' => 'datetime',
        'eta_computed_at' => 'datetime',
    ];

    protected $hidden = ['push_target'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function center()
    {
        return $this->belongsTo(LogisticsCenter::class, 'center_id');
    }

    public function hasLocation(): bool
    {
        return $this->last_lat !== null && $this->last_lng !== null && $this->last_location_at !== null;
    }

    public function hasPushTarget(): bool
    {
        return $this->push_target_type !== null && trim((string) $this->push_target) !== '';
    }
}
