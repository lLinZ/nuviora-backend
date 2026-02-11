<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class OrderAssignedNotification extends Notification
{

    public $order;
    public $message;

    /**
     * Create a new notification instance.
     */
    public function __construct(Order $order, string $message)
    {
        $this->order = $order;
        $this->message = $message;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'message' => $this->message,
            'url' => '/orders?id=' . $this->order->id
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'order_id' => $this->order->id,
            'message' => $this->message,
            'url' => '/orders?id=' . $this->order->id,
            'sound' => 'notification_sound' // Frontend can use this key to play a sound
        ]);
    }
}
