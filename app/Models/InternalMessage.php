<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class InternalMessage extends Model
{
    use HasFactory;

    /** 50 MB por archivo (en el servidor: upload_max_filesize 50M, post_max_size 55M, nginx 55M; 2026-10-06). */
    public const MAX_KB = 51200;

    /**
     * Extensiones aceptadas, según el contenido real del archivo (no el nombre). Sin HTML, SVG ni ejecutables:
     * lo que no es foto, video, audio o PDF se sirve siempre como descarga.
     */
    public const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif',
        'mp4', 'm4v', 'mov', 'qt', 'webm', '3gp', '3g2', 'mkv', 'avi',
        'mp3', 'mpga', 'm4a', 'mp4a', 'aac', 'ogg', 'oga', 'opus', 'spx', 'wav', 'weba', 'amr', 'flac',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'csv', 'txt', 'rtf', 'zip', 'rar',
    ];

    /** Fotos que el navegador muestra (una HEIC del iPhone va como documento para descargar). */
    private const INLINE_IMAGES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];

    /** image | video | audio | voice | pdf se abren en el chat; file se descarga. */
    private const INLINE_KINDS = ['image', 'video', 'audio', 'voice', 'pdf'];

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'attachment_size',
        'attachment_kind',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'attachment_size' => 'integer',
    ];

    public function conversation()
    {
        return $this->belongsTo(InternalConversation::class, 'conversation_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** Una nota de voz grabada en el navegador puede llegar como video/webm o video/mp4: se sirve como audio. */
    public static function servedMime(string $mime, bool $voice): string
    {
        if (!$voice) {
            return $mime;
        }

        return match ($mime) {
            'video/webm' => 'audio/webm',
            'video/mp4' => 'audio/mp4',
            'application/ogg' => 'audio/ogg',
            default => $mime,
        };
    }

    public static function kindFor(string $mime, bool $voice): string
    {
        if (str_starts_with($mime, 'audio/') || $mime === 'application/ogg') {
            return $voice ? 'voice' : 'audio';
        }
        if (in_array($mime, self::INLINE_IMAGES, true)) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        return $mime === 'application/pdf' ? 'pdf' : 'file';
    }

    /** Nombre para mostrar y descargar: sin rutas ni caracteres de control, y con extensión. */
    public static function cleanName(?string $original, ?string $extension): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', basename((string) $original)));
        if (mb_strlen($name) > 120) {
            // Se acorta el nombre, no la extensión
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = Str::limit(pathinfo($name, PATHINFO_FILENAME), 110, '') . ($ext !== '' ? '.' . Str::limit($ext, 8, '') : '');
        }
        if ($name === '') {
            $name = 'archivo' . ($extension ? ".{$extension}" : '');
        }

        return $name;
    }

    public function isInline(): bool
    {
        return in_array($this->attachment_kind, self::INLINE_KINDS, true);
    }

    /** El adjunto para el chat, con un enlace firmado que vence en 12 h (como los comprobantes). */
    public function attachmentPayload(): ?array
    {
        if (!$this->attachment_path) {
            return null;
        }
        $url = URL::temporarySignedRoute('internal-chat.attachment', now()->addHours(12), ['message' => $this->id]);

        return [
            'url' => $url,
            'download_url' => $url . '&download=1',
            'name' => $this->attachment_name,
            'mime' => $this->attachment_mime,
            'size' => $this->attachment_size,
            'kind' => $this->attachment_kind,
        ];
    }

    /** Lo que se ve en la bandeja y en el aviso de la agencia: el texto, o qué archivo es. */
    public function preview(): string
    {
        $body = trim((string) $this->body);
        if (!$this->attachment_kind) {
            return $body;
        }
        $label = match ($this->attachment_kind) {
            'image' => '📷 Foto',
            'video' => '🎥 Video',
            'voice' => '🎤 Nota de voz',
            'audio' => '🎵 Audio',
            default => '📄 ' . $this->attachment_name,
        };

        return $body !== '' ? "{$label} · {$body}" : $label;
    }
}
