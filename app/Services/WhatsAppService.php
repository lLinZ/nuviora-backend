<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class WhatsAppService
{
    protected $accessToken;
    protected $phoneNumberId;
    protected $baseUrl = 'https://graph.facebook.com/v21.0';

    protected $wabaId;

    /** Último error devuelto por Meta al subir o enviar un archivo (para mostrárselo a la vendedora). */
    public ?string $lastError = null;

    /** Tipos de archivo que acepta WhatsApp Cloud API, por tipo de mensaje. */
    private const MEDIA_TYPES = [
        'image'    => ['image/jpeg', 'image/png'],
        'video'    => ['video/mp4', 'video/3gpp'],
        'audio'    => ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg', 'audio/opus'],
        'document' => [
            'application/pdf', 'text/plain',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
    ];

    /**
     * 🔥 Tarea 11: deja un archivo listo para WhatsApp y devuelve [ruta, mime, tipo de mensaje].
     * Meta solo acepta video MP4/3GPP con H.264 (+ AAC) de hasta 16 MB: los .mov del iPhone,
     * .webm o MP4 en HEVC se convierten a MP4 H.264 con ffmpeg (si ya es H.264 solo se cambia
     * el contenedor, en segundos). Lanza RuntimeException con un mensaje para la vendedora.
     */
    public function prepareMedia(string $path, string $mime): array
    {
        if (str_starts_with($mime, 'video/')) {
            $codec = $this->videoCodec($path);
            if (in_array($mime, self::MEDIA_TYPES['video'], true) && $codec === 'h264') {
                return [$path, $mime, 'video'];
            }

            $mp4 = $this->convertToMp4($path, $codec === 'h264');
            if (filesize($mp4) > 16 * 1024 * 1024) {
                throw new \RuntimeException('El video pesa más de 16 MB después de convertirlo (máximo de WhatsApp). Envía un video más corto.');
            }
            return [$mp4, 'video/mp4', 'video'];
        }

        foreach (self::MEDIA_TYPES as $type => $mimes) {
            if (in_array($mime, $mimes, true)) {
                return [$path, $mime, $type];
            }
        }

        // Imágenes o audios que WhatsApp no reproduce (webp, heic, wav...) y el resto: como documento
        return [$path, $mime, 'document'];
    }

    /** PHP-FPM suele correr sin PATH: se buscan también en /usr/bin y /usr/local/bin. */
    private function binary(string $name): string
    {
        return (new \Symfony\Component\Process\ExecutableFinder())->find($name, $name, ['/usr/bin', '/usr/local/bin']);
    }

    private function videoCodec(string $path): ?string
    {
        $result = Process::timeout(15)->run([
            $this->binary('ffprobe'), '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=codec_name', '-of', 'default=nw=1:nk=1', $path,
        ]);

        return $result->successful() ? (trim($result->output()) ?: null) : null;
    }

    private function convertToMp4(string $path, bool $isH264): string
    {
        $out = preg_replace('/\.[^.\/]+$/', '', $path) . '-wa.mp4';

        // Si ya es H.264 solo se reempaqueta (el audio se pasa a AAC); si no, se recodifica a 720p máx.
        $codecArgs = $isH264
            ? ['-c:v', 'copy']
            : ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-pix_fmt', 'yuv420p',
               '-vf', "scale='if(gt(iw,ih),min(1280,iw),-2)':'if(gt(iw,ih),-2,min(1280,ih))'"];

        $result = Process::timeout(55)->run(array_merge(
            [$this->binary('ffmpeg'), '-y', '-loglevel', 'error', '-i', $path],
            $codecArgs,
            ['-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $out]
        ));

        if (!$result->successful() || !is_file($out) || filesize($out) === 0) {
            Log::error('WhatsApp Video Conversion Error', ['file' => basename($path), 'error' => substr($result->errorOutput(), -500)]);
            throw new \RuntimeException('No se pudo convertir el video a MP4. Intenta enviarlo en MP4 desde el teléfono.');
        }

        return $out;
    }

    public function __construct()
    {
        $this->accessToken = config('services.whatsapp.access_token');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
        $this->wabaId = config('services.whatsapp.waba_id');
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

            return $response->json() ?: ['error' => $response->body(), 'status' => $response->status()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Service Exception', [
                'message' => $e->getMessage(),
                'to' => $cleanTo
            ]);
            return ['error' => $e->getMessage()];
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

            return $response->json() ?: ['error' => $response->body(), 'status' => $response->status()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Template Exception', [
                'message' => $e->getMessage(),
                'to' => $cleanTo
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Upload media to Meta WhatsApp servers.
     */
    /**
     * Sube un archivo a Meta. $mime es el tipo real del archivo (usar prepareMedia() antes):
     * antes se mandaba "video"/"image" y el tipo del archivo se adivinaba por la extensión,
     * por eso Meta rechazaba los .mov y similares.
     */
    public function uploadMedia($filePath, $mime)
    {
        $url = "{$this->baseUrl}/{$this->phoneNumberId}/media";
        $this->lastError = null;

        try {
            $response = Http::withToken($this->accessToken)
                ->withoutVerifying()
                ->attach('file', file_get_contents($filePath), basename($filePath), ['Content-Type' => $mime])
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'type' => $mime
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            $this->lastError = $response->json('error.message') ?? $response->body();
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

            $this->lastError = $response->json('error.message') ?? $response->body();
            Log::error('WhatsApp Media Send Error', [
                'type' => $type,
                'status' => $response->status(),
                'body' => $response->body(),
                'to' => $cleanTo
            ]);

            return $response->json() ?: ['error' => $response->body(), 'status' => $response->status()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Media Send Exception', [
                'message' => $e->getMessage(),
                'to' => $cleanTo
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Send a media message (image, video, audio, document) using a public URL.
     */
    public function sendMediaByUrl($to, $url, $type, $caption = null)
    {
        $cleanTo = $this->cleanNumber($to);
        $apiUrl = "{$this->baseUrl}/{$this->phoneNumberId}/messages";

        $data = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $cleanTo,
            'type' => $type,
            $type => [
                'link' => $url
            ]
        ];

        if ($caption && in_array($type, ['image', 'video', 'document'])) {
            $data[$type]['caption'] = $caption;
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withoutVerifying()
                ->post($apiUrl, $data);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('WhatsApp Media Send URL Error', [
                'type' => $type,
                'status' => $response->status(),
                'body' => $response->body(),
                'to' => $cleanTo,
                'url' => $url
            ]);

            return $response->json() ?: ['error' => $response->body(), 'status' => $response->status()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Media Send URL Exception', [
                'message' => $e->getMessage(),
                'to' => $cleanTo,
                'url' => $url
            ]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get all message templates from Meta (WhatsApp Business Account).
     * Requires WHATSAPP_WABA_ID in .env
     */
    public function getMetaTemplates()
    {
        if (!$this->wabaId) {
            return ['error' => 'WHATSAPP_WABA_ID no está configurado en el servidor. Agrégalo al .env del VPS.'];
        }

        $url = "{$this->baseUrl}/{$this->wabaId}/message_templates";

        try {
            $response = Http::withToken($this->accessToken)
                ->withoutVerifying()
                ->get($url, [
                    'fields' => 'name,status,language,category,components,id',
                    'limit'  => 100,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('WhatsApp Meta Templates Error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return ['error' => 'Error al consultar Meta API: ' . $response->body()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Meta Templates Exception', [
                'message' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
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
