<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'shopify_domain',
        'shopify_access_token',
        'shopify_webhook_secret',
        'status_id',
        'auto_open_at',
        'auto_close_at',
        'auto_schedule_enabled',
    ];

    protected $casts = [
        'auto_schedule_enabled' => 'boolean',
    ];

    protected $appends = ['is_open'];

    /**
     * Determina si la tienda está abierta basado en el horario automático 
     * y la hora actual del servidor.
     */
    public function getIsOpenAttribute()
    {
        if (!$this->auto_schedule_enabled || !$this->auto_open_at || !$this->auto_close_at) {
            return true; // Si no hay horario, asumimos abierta por defecto o gestionada manualmente
        }

        $now = now()->format('H:i:s');
        $open = $this->auto_open_at;
        $close = $this->auto_close_at;

        if ($open < $close) {
            // Horario normal (ej: 08:00 a 20:00)
            return $now >= $open && $now <= $close;
        } else {
            // Horario cruzando medianoche (ej: 22:00 a 06:00)
            return $now >= $open || $now <= $close;
        }
    }

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function sellers()
    {
        return $this->belongsToMany(User::class, 'shop_user')->withPivot('is_default_roster');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
