<?php

// app/Models/OrderAssignmentLog.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderAssignmentLog extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'agent_id', 'strategy', 'assigned_by', 'meta'];

    protected $casts = [
        'meta' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
