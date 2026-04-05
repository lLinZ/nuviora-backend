<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappTemplate extends Model
{
    protected $fillable = [
        'name',
        'label',
        'body',
        'is_official',
        'meta_components',
    ];

    protected $casts = [
        'is_official'     => 'boolean',
        'meta_components' => 'array',
    ];
}
