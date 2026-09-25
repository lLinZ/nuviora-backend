<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssignmentPool extends Model
{
    protected $fillable = ['key', 'state'];

    protected $casts = [
        'state' => 'array',
    ];
}
