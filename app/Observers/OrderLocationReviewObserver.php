<?php

namespace App\Observers;

use App\Models\OrderLocationReview;
use App\Models\OrderActivityLog;

class OrderLocationReviewObserver
{
    public function created(OrderLocationReview $review): void
    {
        OrderActivityLog::create([
            'order_id' => $review->order_id,
            'user_id' => auth()->id(),
            'action' => 'location_review_requested',
            'description' => "Solicitó cambio de ubicación.",
            'properties' => $review->toArray()
        ]);
    }

    public function updated(OrderLocationReview $review): void
    {
        if ($review->isDirty('status')) {
            $status = $review->status === 'approved' ? 'APROBÓ' : 'RECHAZÓ';
            OrderActivityLog::create([
                'order_id' => $review->order_id,
                'user_id' => auth()->id(),
                'action' => "location_review_{$review->status}",
                'description' => "{$status} el cambio de ubicación. Nota: " . ($review->response_note ?? 'Sin nota'),
                'properties' => $review->toArray()
            ]);
        }
    }
}
