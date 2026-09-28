<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Inventory;

/**
 * Compara el total de cada inventario con la suma de sus tallas. Por defecto solo informa: forzar el
 * total a la suma de tallas borraba ventas sin talla y traslados (tarea 3, punto 5). Con --apply lo
 * sigue haciendo, para quien lo necesite a propósito.
 */
class SyncInventoryTotals extends Command
{
    protected $signature = 'inventory:sync-totals {--apply : Poner el total igual a la suma de tallas}';
    protected $description = 'Informa (o con --apply corrige) inventarios cuyo total no coincide con la suma de sus tallas';

    public function handle()
    {
        $inventories = Inventory::all();
        $this->info("Revisando {$inventories->count()} inventarios...");
        $mismatches = 0;

        foreach ($inventories as $inv) {
            if (empty($inv->sizes_stock) || !is_array($inv->sizes_stock)) {
                continue;
            }

            $totalFromSizes = array_sum($inv->sizes_stock);
            $expected = $totalFromSizes + (int) $inv->defective_stock;

            if ((int) $inv->quantity !== $totalFromSizes && (int) $inv->quantity !== $expected) {
                $mismatches++;
                $this->warn("ID {$inv->id} (producto {$inv->product_id}, almacén {$inv->warehouse_id}): total {$inv->quantity} | suma de tallas {$totalFromSizes}");
                if ($this->option('apply')) {
                    $inv->quantity = $totalFromSizes;
                    $inv->save();
                    $this->info('Corregido.');
                }
            }
        }

        $this->info($mismatches ? "{$mismatches} con diferencias." : 'Sin diferencias.');
        if ($mismatches && !$this->option('apply')) {
            $this->line('No se cambió nada. Para forzar el total a la suma de tallas: --apply');
        }
    }
}
