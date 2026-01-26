<?php

namespace App\Observers;

use App\Models\OrderRejectionReview;
use App\Models\OrderActivityLog;

class OrderRejectionReviewObserver
{
    public function created(OrderRejectionReview $review): void
    {
        OrderActivityLog::create([
            'order_id' => $review->order_id,
            'user_id' => auth()->id(),
            'action' => 'rejection_review_requested',
            'description' => "Solicitó rechazar la orden. Razón: " . ($review->request_note ?? 'Sin nota'),
            'properties' => $review->toArray()
        ]);
    }

    public function updated(OrderRejectionReview $review): void
    {
        if ($review->isDirty('status')) {
            $status = $review->status === 'approved' ? 'APROBÓ' : 'RECHAZÓ';
            OrderActivityLog::create([
                'order_id' => $review->order_id,
                'user_id' => auth()->id(),
                'action' => "rejection_review_{$review->status}",
                'description' => "{$status} el rechazo de la orden. Nota: " . ($review->response_note ?? 'Sin nota'),
                'properties' => $review->toArray()
            ]);
        }
    }
}
