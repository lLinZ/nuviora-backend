<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class OrderNoStockNotification extends Notification
{
    public $order;
    public $message;

    public function __construct(Order $order, string $message)
    {
        $this->order   = $order;
        $this->message = $message;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'message'  => $this->message,
            'type'     => 'no_stock',
            'url'      => '/orders?id=' . $this->order->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'order_id' => $this->order->id,
            'message'  => $this->message,
            'type'     => 'no_stock',
            'url'      => '/orders?id=' . $this->order->id,
            'sound'    => 'alert_sound',
        ]);
    }
}
