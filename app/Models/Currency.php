<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Currency extends Model
{
    //
    use HasFactory;
    public function status()
    {
        return $this->belongsTo(Status::class);
    }
    protected $fillable = [
        'description',
        'value',
        'status_id'
    ];
}
