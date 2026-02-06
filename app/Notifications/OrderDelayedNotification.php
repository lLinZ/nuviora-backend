<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderDelayedNotification extends Notification
{
    use Queueable;

    public $order;
    public $message;

    /**
     * Create a new notification instance.
     */
    public function __construct($order, $message = null)
    {
        $this->order = $order;
        $this->message = $message ?? "Orden #{$order->name} retrasada (Excedió 45 min)";
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

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Orden Retrasada 🚨',
            'message' => $this->message,
            'type' => 'warning',
            'order_id' => $this->order->id,
            'action_url' => "/orders?id={$this->order->id}",
            'sound' => 'waiting_sound',
        ];
    }
}
