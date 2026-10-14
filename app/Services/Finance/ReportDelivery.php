<?php

namespace App\Services\Finance;

use App\Models\FinancialReportVersion;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * El reporte por WhatsApp a Fran (Módulo 4 de Fran, R18: "si se puede, que me llegue por WhatsApp"). Fuera de la ventana
 * de 24 horas Meta solo deja escribir con una plantilla aprobada: la configuración dice cuál (con el PDF en el encabezado,
 * DOCUMENT) y a qué números. El texto de la plantilla no se toca: solo se llenan sus variables, en este orden:
 *   {{1}} el reporte ("Semanal del 05/10 al 11/10/2026"), {{2}} ingresos, {{3}} beneficio operativo, {{4}} caja disponible.
 * Si la plantilla tiene menos variables, se mandan las primeras. Mientras no esté configurada, no se manda nada.
 */
class ReportDelivery
{
    public function __construct(private WhatsAppService $whatsapp, private ReportPdf $pdf)
    {
    }

    /** Lo que se manda solo después de un reporte automático, si está configurado para ese tipo. */
    public function sendIfConfigured(FinancialReportVersion $version): ?array
    {
        $c = FinancialReports::config()['whatsapp'];
        if (!$c['enabled'] || !$c['template'] || !$c['to'] || !in_array($version->report->type, (array) $c['types'], true)) {
            return null;
        }

        return $this->send($version);
    }

    /**
     * Manda la versión a los números configurados. Devuelve el resultado por número y lo guarda en la versión.
     * @return array<int, array{to: string, ok: bool, id?: string, error?: string}>
     */
    public function send(FinancialReportVersion $version): array
    {
        $c = FinancialReports::config()['whatsapp'];
        if (!$c['template'] || !$c['to']) {
            throw ValidationException::withMessages(['whatsapp' => 'Falta configurar la plantilla de WhatsApp y a qué números se manda (Finanzas › Reportes › Envío por WhatsApp).']);
        }
        $template = $this->templateInfo((string) $c['template'], (string) $c['language']);
        $file = Storage::disk('local')->path($this->pdf->path($version));
        $upload = $this->whatsapp->uploadMedia($file, 'application/pdf');
        $results = [];
        if (!is_array($upload) || empty($upload['id'])) {
            $error = 'No se pudo subir el PDF a WhatsApp' . ($this->whatsapp->lastError ? ": {$this->whatsapp->lastError}" : '.');
            foreach ($c['to'] as $to) {
                $results[] = ['to' => $to, 'ok' => false, 'error' => $error];
            }

            return $this->store($version, $results);
        }

        $content = $version->content();
        $money = fn ($v) => ((float) $v < 0 ? '-$' : '$') . number_format(abs((float) $v), 2, ',', '.');
        $values = [
            $version->report->title(),
            $money($content['company']['revenue'] ?? 0),
            $money($content['company']['operating_profit'] ?? 0),
            $money($content['cash']['available_usd'] ?? 0),
        ];
        $components = [['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['id' => $upload['id'], 'filename' => ReportPdf::filename($version)]]]]];
        if ($template['variables'] > 0) {
            $components[] = ['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => $v], array_slice($values, 0, $template['variables']))];
        }
        foreach ($c['to'] as $to) {
            $res = $this->whatsapp->sendTemplate($to, $c['template'], $c['language'], $components);
            $id = $res['messages'][0]['id'] ?? null;
            $results[] = $id ? ['to' => $to, 'ok' => true, 'id' => $id]
                : ['to' => $to, 'ok' => false, 'error' => WhatsAppService::explainError($res['error'] ?? null, is_string($res['error'] ?? null) ? $res['error'] : 'Meta no aceptó el envío.')];
        }

        return $this->store($version, $results);
    }

    /**
     * La plantilla aprobada en Meta: cuántas variables tiene su cuerpo. Si Meta no responde, se asume que tiene las 4.
     * @return array{variables: int}
     */
    public function templateInfo(string $name, string $language): array
    {
        $list = $this->whatsapp->getMetaTemplates();
        if (!isset($list['data'])) {
            return ['variables' => 4];
        }
        $template = collect($list['data'])->first(fn ($t) => ($t['name'] ?? null) === $name && ($t['language'] ?? null) === $language);
        if (!$template) {
            throw ValidationException::withMessages(['whatsapp' => "La plantilla \"{$name}\" ({$language}) no existe en Meta."]);
        }
        if (($template['status'] ?? '') !== 'APPROVED') {
            throw ValidationException::withMessages(['whatsapp' => "La plantilla \"{$name}\" todavía no está aprobada por Meta ({$template['status']})."]);
        }
        $components = collect($template['components'] ?? []);
        $header = $components->firstWhere('type', 'HEADER');
        if (($header['format'] ?? null) !== 'DOCUMENT') {
            throw ValidationException::withMessages(['whatsapp' => "La plantilla \"{$name}\" no lleva un documento en el encabezado: el PDF no se puede adjuntar."]);
        }
        preg_match_all('/\{\{\s*\d+\s*\}\}/', (string) ($components->firstWhere('type', 'BODY')['text'] ?? ''), $m);

        return ['variables' => count(array_unique($m[0]))];
    }

    private function store(FinancialReportVersion $version, array $results): array
    {
        $version->forceFill([
            'whatsapp_result' => $results,
            'whatsapp_sent_at' => collect($results)->contains('ok', true) ? now() : $version->whatsapp_sent_at,
        ])->saveQuietly();

        return $results;
    }
}
