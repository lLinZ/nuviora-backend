<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    //
    use HasFactory;
    public function products()
    {
        return $this->hasMany(OrderProduct::class);
    }
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }
    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
    public function updates()
    {
        return $this->hasMany(OrderUpdate::class);
    }
    public function cancellations() {
        return $this->hasMany(OrderCancellation::class);
    }
    protected $fillable = [
        'order_id',
        'name',
        'current_total_price',
        'order_number',
        'processed_at',
        'currency',
        'client_id',
        'status_id',
        'agent_id'
    ];
}
