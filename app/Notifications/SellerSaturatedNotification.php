<?php

namespace App\Notifications;

use App\Models\SaturationAlert;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** Aviso en tiempo real a la Líder y a Administración: una vendedora está saturada (spec §9.2). */
class SellerSaturatedNotification extends Notification
{
    public function __construct(private SaturationAlert $alert, private string $message, private string $url)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    private function payload(): array
    {
        return [
            'type' => 'saturation',
            'message' => $this->message,
            'url' => $this->url,
            'seller_id' => $this->alert->seller_id,
            'sales_group_id' => $this->alert->sales_group_id,
            'load' => $this->alert->load,
            'group_average' => $this->alert->group_average,
            'over_pct' => $this->alert->over_pct,
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload() + ['sound' => 'notification_sound']);
    }
}
