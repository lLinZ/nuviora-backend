<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesGroupMember extends Model
{
    public const ROLE_SELLER = 'seller';
    public const ROLE_LEADER = 'leader';

    protected $fillable = [
        'sales_group_id', 'user_id', 'role', 'weight',
        'started_at', 'ended_at', 'added_by', 'removed_by',
    ];

    protected $casts = [
        'weight' => 'float',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(SalesGroup::class, 'sales_group_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('ended_at');
    }

    public function close(?int $byUserId): void
    {
        $this->update(['ended_at' => now(), 'removed_by' => $byUserId]);
    }
}
