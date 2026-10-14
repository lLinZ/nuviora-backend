<?php

namespace App\Services\Finance;

use App\Models\FinancialReportVersion;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * El "reporte ejecutivo visual" en PDF (Módulo 4 de Fran, §80), de una versión de un reporte. Se arma una sola vez por
 * versión y se guarda: una versión no cambia (§20: si cambian las cifras, nace otra).
 */
class ReportPdf
{
    public const DIR = 'finance-reports';

    /** El PDF de la versión: el guardado o, si no existe, uno nuevo. Devuelve la ruta en el disco local. */
    public function path(FinancialReportVersion $version): string
    {
        if ($version->pdf_path && Storage::disk('local')->exists($version->pdf_path)) {
            return $version->pdf_path;
        }
        $path = self::DIR . "/reporte-{$version->financial_report_id}-v{$version->version}.pdf";
        Storage::disk('local')->put($path, $this->render($version));
        $version->forceFill(['pdf_path' => $path])->saveQuietly();

        return $path;
    }

    public function render(FinancialReportVersion $version): string
    {
        $report = $version->report;
        $html = view('finance.report-pdf', ['report' => $report, 'version' => $version, 'r' => $version->content()])->render();
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        // El número de página, en todas (después de armarlo, cuando ya se sabe cuántas son)
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $dompdf->getCanvas()->page_text(530, 815, '{PAGE_NUM} / {PAGE_COUNT}', $font, 7, [0.42, 0.45, 0.5]);

        return $dompdf->output();
    }

    /** "Nuviora - Reporte Mensual de septiembre 2026 (v2).pdf" */
    public static function filename(FinancialReportVersion $version): string
    {
        $title = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $version->report->title());

        return "Nuviora - Reporte {$title}" . ($version->version > 1 ? " (v{$version->version})" : '') . '.pdf';
    }
}
