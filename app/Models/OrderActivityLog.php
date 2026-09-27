<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'user_id',
        'actor_role',
        'action',
        'description',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    /**
     * Guarda con qué rol actuó quien hizo el cambio (spec de la Líder §15). Se hace aquí para no
     * tocar las decenas de lugares que escriben el historial. La Líder figura como "Líder".
     */
    protected static function booted(): void
    {
        static::creating(function (OrderActivityLog $log) {
            if ($log->actor_role !== null) {
                return;
            }
            $user = $log->user_id ? User::with('role:id,description')->find($log->user_id) : null;
            $log->actor_role = match (true) {
                $user === null => 'Sistema',
                $user->ledGroup() !== null => 'Líder',
                default => $user->role?->description,
            };
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withDefault([
            'id' => 0,
            'names' => 'Sistema',
            'surnames' => '',
            'email' => 'system@nuviora.com'
        ]);
    }
}
