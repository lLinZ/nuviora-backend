<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Historial de altas y bajas en el roster del día (quién, dónde y por qué). */
class RosterChange extends Model
{
    protected $fillable = ['date', 'shop_id', 'agent_id', 'is_active', 'reason', 'changed_by', 'changed_by_role'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
