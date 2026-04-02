<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Webhook extends Model
{
    protected $fillable = [
        'name',
        'url',
        'event_type',
        'status_id',
        'is_active',
    ];

    public function status()
    {
        return $this->belongsTo(Status::class);
    }
}
