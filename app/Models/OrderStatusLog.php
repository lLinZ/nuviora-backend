<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderStatusLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'from_status_id',
        'to_status_id',
        'user_id',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function fromStatus()
    {
        return $this->belongsTo(Status::class, 'from_status_id');
    }

    public function toStatus()
    {
        return $this->belongsTo(Status::class, 'to_status_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
