<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DailyAgentRoster extends Model
{
    use HasFactory;

    protected $fillable = ['date', 'agent_id', 'is_active'];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
