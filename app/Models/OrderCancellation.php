<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderCancellation extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'user_id',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'response_note',
        'previous_status_id',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    } // quien pidió
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
    public function previousStatus()
    {
        return $this->belongsTo(Status::class, 'previous_status_id');
    }
}
