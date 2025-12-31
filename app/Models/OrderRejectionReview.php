<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderRejectionReview extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'status',
        'request_note',
        'response_note',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
