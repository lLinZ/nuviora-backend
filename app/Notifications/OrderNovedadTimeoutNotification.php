<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderNovedadTimeoutNotification extends Notification
{
    use Queueable;

    public $order;
    public $message;

    /**
     * Create a new notification instance.
     */
    public function __construct($order)
    {
        $this->order = $order;
        $this->message = "Orden #{$order->name} lleva 10 min en Novedad sin solución.";
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
            'title' => 'Novedad pendiente ⏳',
            'message' => $this->message,
            'type' => 'warning',
            'order_id' => $this->order->id,
            'action_url' => "/orders?id={$this->order->id}",
        ];
    }
}
