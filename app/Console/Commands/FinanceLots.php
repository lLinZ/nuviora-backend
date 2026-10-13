<?php

namespace App\Console\Commands;

use App\Services\Finance\LotLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Libro de lotes (Módulo 4 de Fran, entrega 2a).
 * - Sin opciones (cada minuto): si todavía no hay corte, lo toma (lote inicial, R5); después procesa los movimientos
 *   de inventario nuevos.
 * - --replay: lo rehace todo desde el corte (es determinista).
 * - --check: compara las capas con el stock de cada almacén + producto + variante (solo lee).
 */
class FinanceLots extends Command
{
    protected $signature = 'finance:lots {--replay} {--check} {--max-time=50}';

    protected $description = 'Mantiene el inventario por lotes (FIFO) al día con los movimientos de inventario';

    public function handle(LotLedger $ledger): int
    {
        if ($this->option('check')) {
            return $this->check($ledger);
        }
        if ($this->option('replay')) {
            $this->info('Movimientos reprocesados desde el corte: ' . $ledger->replay(3600) . '.');

            return self::SUCCESS;
        }
        if (!LotLedger::initialized()) {
            $s = $ledger->initialize();
            $this->info("Corte tomado en el movimiento {$s['cutoff']}: {$s['lots']} lotes ({$s['without_cost']} sin costo), {$s['layers']} capas, "
                . "{$s['units']} unidades disponibles, {$s['review']} en revisión y {$s['held']} fuera en órdenes abiertas.");

            return self::SUCCESS;
        }
        $done = $ledger->process(LotLedger::DEFAULT_LAG_SECONDS, (int) $this->option('max-time'));
        if ($done > 0) {
            $this->info("Movimientos procesados: {$done}.");
        }

        return self::SUCCESS;
    }

    private function check(LotLedger $ledger): int
    {
        if (!LotLedger::initialized()) {
            $this->warn('El libro de lotes todavía no tiene corte.');

            return self::SUCCESS;
        }
        $result = $ledger->check();
        if ($result['rows']) {
            $warehouses = DB::table('warehouses')->pluck('name', 'id');
            $products = DB::table('products')->pluck('title', 'id');
            $variants = DB::table('product_variants')->pluck('title', 'id');
            $this->table(['Almacén', 'Producto', 'Variante', 'Stock', 'En lotes', 'Defectuosas', 'En revisión'], array_map(fn ($r) => [
                $warehouses[$r['warehouse_id']] ?? $r['warehouse_id'],
                mb_substr((string) ($products[$r['product_id']] ?? $r['product_id']), 0, 40),
                $r['variant_id'] ? ($variants[$r['variant_id']] ?? $r['variant_id']) : 'sin variante',
                $r['stock'], $r['lots'], $r['defective'], $r['review'],
            ], $result['rows']));
        }
        $this->info(count($result['rows']) . ' diferencias. Movimientos sin procesar: ' . $result['pending'] . '.');

        return self::SUCCESS;
    }
}
