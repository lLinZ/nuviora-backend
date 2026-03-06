<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected $accessToken;
    protected $phoneNumberId;
    protected $baseUrl = 'https://graph.facebook.com/v21.0';

    public function __construct()
    {
        $this->accessToken = env('WHATSAPP_ACCESS_TOKEN');
        $this->phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID');
    }

    /**
     * Send a text message to a phone number.
     *
     * @param string $to
     * @param string $message
     * @return array|bool
     */
    public function sendMessage($to, $message)
    {
        // 1. Limpiar el número (quitar +, espacios, etc)
        $cleanTo = preg_replace('/[^0-9]/', '', $to);

        // 2. Normalizar número para Venezuela (58)
        // Si empieza con 04..., convertir a 584...
        if (strpos($cleanTo, '04') === 0) {
            $cleanTo = '58' . substr($cleanTo, 1);
        } 
        // Si tiene 10 dígitos y empieza con 4... (ej: 4121234567), añadir 58
        elseif (strlen($cleanTo) === 10 && strpos($cleanTo, '4') === 0) {
            $cleanTo = '58' . $cleanTo;
        }

        $url = "{$this->baseUrl}/{$this->phoneNumberId}/messages";

        try {
            $response = Http::withToken($this->accessToken)
                ->withoutVerifying() // 🛡️ Fix for local SSL certificate issues
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $cleanTo,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $message
                    ]
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('WhatsApp API Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'to' => $cleanTo
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('WhatsApp Service Exception', [
                'message' => $e->getMessage(),
                'to' => $cleanTo
            ]);
            return false;
        }
    }
}
