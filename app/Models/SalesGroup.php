<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesGroup extends Model
{
    protected $fillable = ['name', 'leader_load', 'leader_commission_pct', 'is_active', 'created_by'];

    protected $casts = [
        'leader_load' => 'float',
        'leader_commission_pct' => 'float',
        'is_active' => 'boolean',
    ];

    public function members()
    {
        return $this->hasMany(SalesGroupMember::class);
    }

    /** Membresías vigentes (vendedoras y Líder). */
    public function openMembers()
    {
        return $this->hasMany(SalesGroupMember::class)->whereNull('ended_at');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
