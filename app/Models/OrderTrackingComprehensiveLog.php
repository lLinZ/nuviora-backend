<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderTrackingComprehensiveLog extends Model
{
    protected $fillable = [
        'order_id',
        'from_status_id',
        'to_status_id',
        'seller_id',
        'user_id',
        'was_unassigned',
        'was_reassigned',
        'previous_seller_id'
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

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function previousSeller()
    {
        return $this->belongsTo(User::class, 'previous_seller_id');
    }

    public static function log(Order $order, $toStatusId, $userId = null)
    {
        $previousSellerId = $order->getOriginal('agent_id');
        $currentSellerId = $order->agent_id;

        return self::create([
            'order_id' => $order->id,
            'from_status_id' => $order->getOriginal('status_id'),
            'to_status_id' => $toStatusId,
            'seller_id' => $currentSellerId,
            'user_id' => $userId ?? auth()->id(),
            'was_unassigned' => $previousSellerId && !$currentSellerId,
            'was_reassigned' => $previousSellerId && $currentSellerId && $previousSellerId != $currentSellerId,
            'previous_seller_id' => $previousSellerId,
        ]);
    }
}
