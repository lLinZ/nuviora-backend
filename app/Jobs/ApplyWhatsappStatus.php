<?php

namespace App\Jobs;

use App\Events\WhatsappMessageStatus;
use App\Services\WhatsAppDeliveryStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Segundo intento de un rechazo de Meta que llegó antes de que el envío guardara su message_id (15/10/2026):
 * el 131042 llega en el mismo segundo del envío. Se aplica una sola vez, unos segundos después.
 */
class ApplyWhatsappStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public array $status)
    {
    }

    public function handle(): void
    {
        $message = WhatsAppDeliveryStatus::apply($this->status);
        if ($message) {
            event(new WhatsappMessageStatus($message));
        }
    }
}
