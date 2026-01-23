<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Setting;

class Order extends Model
{
    //
    use HasFactory;
 
    protected static function booted()
    {
        static::updated(function ($order) {
            if ($order->isDirty('status_id')) {
                \App\Models\OrderStatusLog::create([
                    'order_id' => $order->id,
                    'from_status_id' => $order->getOriginal('status_id'),
                    'to_status_id' => $order->status_id,
                    'user_id' => \Illuminate\Support\Facades\Auth::id(),
                ]);
            }
        });

        static::created(function ($order) {
            \App\Models\OrderStatusLog::create([
                'order_id' => $order->id,
                'from_status_id' => null,
                'to_status_id' => $order->status_id,
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
            ]);
        });
    }

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

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    protected $appends = ['ves_price', 'bcv_equivalence'];

    public function getVesPriceAttribute()
    {
        $rate = (float) Setting::get('rate_binance_usd', 0);
        return $this->current_total_price * $rate;
    }

    public function getBcvEquivalenceAttribute()
    {
        $ves = $this->ves_price;
        $rateBcv = (float) Setting::get('rate_bcv_usd', 0);
        return $rateBcv > 0 ? $ves / $rateBcv : 0;
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
        'shop_id',
        'was_shipped',
        'shipped_at',
        'city_id',
        'agency_id',
        'delivery_cost',
        'cash_received',
        'change_amount',
        'change_covered_by',
        'change_amount_company',
        'change_amount_agency',
        'change_method_company',
        'change_method_agency',
        'novedad_type',
        'novedad_description',
        'novedad_resolution',
        'change_rate',
    ];
}
