<?php

namespace App\Observers;

use App\Models\OrderDeliveryReview;
use App\Models\OrderActivityLog;

class OrderDeliveryReviewObserver
{
    public function created(OrderDeliveryReview $review): void
    {
        OrderActivityLog::create([
            'order_id' => $review->order_id,
            'user_id' => auth()->id(),
            'action' => 'delivery_review_requested',
            'description' => "Solicitó aprobación para marcar como entregado.",
            'properties' => $review->toArray()
        ]);
    }

    public function updated(OrderDeliveryReview $review): void
    {
        if ($review->isDirty('status')) {
            $status = $review->status === 'approved' ? 'APROBÓ' : 'RECHAZÓ';
            OrderActivityLog::create([
                'order_id' => $review->order_id,
                'user_id' => auth()->id(),
                'action' => "delivery_review_{$review->status}",
                'description' => "{$status} la entrega. Nota: " . ($review->response_note ?? 'Sin nota'),
                'properties' => $review->toArray()
            ]);
        }
    }
}
