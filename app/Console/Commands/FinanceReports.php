<?php

namespace App\Console\Commands;

use App\Services\Finance\FinancialReports;
use App\Services\Finance\ReportDelivery;
use Illuminate\Console\Command;

/**
 * Los reportes automáticos (Módulo 4 de Fran, §53 a §55): cada 10 minutos genera el último diario, semanal y mensual que ya
 * debía existir y no existe (y lo manda por WhatsApp si está configurado, R18). Con --refresh, cada noche, recalcula los
 * reportes recientes y crea otra versión si cambiaron sus cifras (§20, §21).
 */
class FinanceReports extends Command
{
    protected $signature = 'finance:reports {--refresh : recalcular los reportes recientes (versión nueva si cambiaron)}';

    protected $description = 'Genera los reportes financieros automáticos y los mantiene al día';

    public function handle(FinancialReports $reports, ReportDelivery $delivery): int
    {
        if ($this->option('refresh')) {
            $made = $reports->refreshRecent();
            $this->info(count($made) . ' reporte(s) con versión nueva.');

            return self::SUCCESS;
        }

        foreach ($reports->runSchedule(now()) as $version) {
            $report = $version->report;
            $this->info("Generado: {$report->title()} ({$version->status})");
            try {
                $delivery->sendIfConfigured($version);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
