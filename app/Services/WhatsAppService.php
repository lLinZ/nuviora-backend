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
        $cleanTo = $this->cleanNumber($to);
        $url = "{$this->baseUrl}/{$this->phoneNumberId}/messages";

        try {
            $response = Http::withToken($this->accessToken)
                ->withoutVerifying()
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

    public function sendTemplate($to, $templateName, $language = 'es', $components = [])
    {
        $cleanTo = $this->cleanNumber($to);
        $url = "{$this->baseUrl}/{$this->phoneNumberId}/messages";

        try {
            $response = Http::withToken($this->accessToken)
                ->withoutVerifying()
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => $cleanTo,
                    'type' => 'template',
                    'template' => [
                        'name' => $templateName,
                        'language' => [
                            'code' => $language
                        ],
                        'components' => $components
                    ]
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('WhatsApp Template Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'to' => $cleanTo,
                'template' => $templateName
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('WhatsApp Template Exception', [
                'message' => $e->getMessage(),
                'to' => $cleanTo
            ]);
            return false;
        }
    }

    /**
     * Upload media to Meta WhatsApp servers.
     */
    public function uploadMedia($filePath, $type)
    {
        $url = "{$this->baseUrl}/{$this->phoneNumberId}/media";

        try {
            $response = Http::withToken($this->accessToken)
                ->withoutVerifying()
                ->attach('file', file_get_contents($filePath), basename($filePath))
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'type' => $type
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('WhatsApp Media Upload Error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('WhatsApp Media Upload Exception', [
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send a media message (image, video, audio) using a media ID.
     */
    public function sendMedia($to, $mediaId, $type, $caption = null)
    {
        $cleanTo = $this->cleanNumber($to);
        $url = "{$this->baseUrl}/{$this->phoneNumberId}/messages";

        $data = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $cleanTo,
            'type' => $type,
            $type => [
                'id' => $mediaId
            ]
        ];

        if ($caption && in_array($type, ['image', 'video', 'document'])) {
            $data[$type]['caption'] = $caption;
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withoutVerifying()
                ->post($url, $data);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('WhatsApp Media Send Error', [
                'type' => $type,
                'status' => $response->status(),
                'body' => $response->body(),
                'to' => $cleanTo
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('WhatsApp Media Send Exception', [
                'message' => $e->getMessage(),
                'to' => $cleanTo
            ]);
            return false;
        }
    }

    private function cleanNumber($to)
    {
        $cleanTo = preg_replace('/[^0-9]/', '', $to);

        if (strpos($cleanTo, '04') === 0) {
            $cleanTo = '58' . substr($cleanTo, 1);
        } elseif (strlen($cleanTo) === 10 && strpos($cleanTo, '4') === 0) {
            $cleanTo = '58' . $cleanTo;
        }

        return $cleanTo;
    }
}
