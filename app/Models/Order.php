<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    //
    use HasFactory;
    public function postponements()
    {
        return $this->hasMany(\App\Models\OrderPostponement::class);
    }
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
    public function deliveryReviews()
    {
        return $this->hasMany(OrderDeliveryReview::class);
    }
    public function locationReviews()
    {
        return $this->hasMany(OrderLocationReview::class);
    }
    public function rejectionReviews()
    {
        return $this->hasMany(OrderRejectionReview::class);
    }

    public function cancellations()
    {
        return $this->hasMany(OrderCancellation::class);
    }
    public function deliverer()
    {
        return $this->belongsTo(\App\Models\User::class, 'deliverer_id');
    }

    public function payments()
    {
        return $this->hasMany(OrderPayment::class);
    }

    protected $fillable = [
        'order_id',
        'order_number',
        'name',
        'current_total_price',
        'currency',
        'processed_at',
        'client_id',
        'status_id',
        'cancelled_at',
        'scheduled_for',
        'agent_id',
        'deliverer_id',
        'payment_method',
        'exchange_rate',
        'payment_receipt',
        'reminder_at',
    ];
}
