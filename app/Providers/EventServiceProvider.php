<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Models\Order;
use App\Observers\OrderObserver;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The model observers for your application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $observers = [
        \App\Models\Client::class => [\App\Observers\ClientObserver::class],
        \App\Models\Order::class => [\App\Observers\OrderObserver::class],
        \App\Models\OrderPayment::class => [\App\Observers\OrderPaymentObserver::class],
        \App\Models\OrderUpdate::class => [\App\Observers\OrderUpdateObserver::class],
        \App\Models\OrderProduct::class => [\App\Observers\OrderProductObserver::class],
        \App\Models\OrderPostponement::class => [\App\Observers\OrderPostponementObserver::class],
        \App\Models\OrderCancellation::class => [\App\Observers\OrderCancellationObserver::class],
        \App\Models\OrderDeliveryReview::class => [\App\Observers\OrderDeliveryReviewObserver::class],
        \App\Models\OrderLocationReview::class => [\App\Observers\OrderLocationReviewObserver::class],
        \App\Models\OrderRejectionReview::class => [\App\Observers\OrderRejectionReviewObserver::class],
        \App\Models\OrderAssignmentLog::class => [\App\Observers\OrderAssignmentLogObserver::class],
        \App\Models\InventoryMovement::class => [\App\Observers\InventoryMovementObserver::class],
        \App\Models\OrderStatusLog::class => [\App\Observers\OrderStatusLogObserver::class],
        \App\Models\Commission::class => [\App\Observers\CommissionObserver::class],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }
}
