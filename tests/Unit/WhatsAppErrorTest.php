<?php

use App\Services\WhatsAppService;

// Hallazgo H11: los rechazos de Meta llegan en inglés; la vendedora los ve en español.

describe('Motivo de rechazo de WhatsApp', function () {
    it('traduce los códigos más comunes', function () {
        expect(WhatsAppService::explainError(['code' => 190, 'message' => 'Error validating access token']))
            ->toContain('acceso de la empresa');
        expect(WhatsAppService::explainError(['code' => 131047, 'message' => 'Re-engagement message']))
            ->toContain('24 horas');
        expect(WhatsAppService::explainError(['code' => 131053, 'message' => 'Media upload error']))
            ->toContain('no pudo procesar el archivo');
    });

    it('distingue el tipo y el tamaño en el código 100', function () {
        expect(WhatsAppService::explainError(['code' => 100, 'message' => '(#100) Param file must be a file with one of the following types: video/mp4, video/3gpp']))
            ->toBe('no acepta ese tipo de archivo.');
        expect(WhatsAppService::explainError(['code' => 100, 'message' => 'File size too large']))
            ->toBe('el archivo pesa más de lo que WhatsApp permite.');
    });

    it('deja el original si no lo conoce, o el cuerpo si no hay mensaje', function () {
        expect(WhatsAppService::explainError(['code' => 999, 'message' => 'Something new']))->toBe('Something new');
        expect(WhatsAppService::explainError(null, 'Bad Gateway'))->toBe('Bad Gateway');
    });
});
