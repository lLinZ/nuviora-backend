<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Revisa el stock por variante contra el total de cada inventario (tarea 4). Solo informa:
 *  - lo repartido por variante no puede superar el total (el resto es stock "sin variante");
 *  - una variante en negativo vendió más de lo que tenía cargado.
 * Antes comparaba el total con el JSON de tallas y con --apply lo pisaba; ese JSON ya no se usa.
 */
class SyncInventoryTotals extends Command
{
    protected $signature = 'inventory:sync-totals';
    protected $description = 'Informa inventarios cuyo stock por variante no cuadra con el total (solo lectura)';

    public function handle()
    {
        $rows = DB::table('inventories')
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->leftJoin('inventory_variants as iv', function ($join) {
                $join->on('iv.warehouse_id', '=', 'inventories.warehouse_id')->on('iv.product_id', '=', 'inventories.product_id');
            })
            ->groupBy('inventories.id', 'inventories.quantity', 'products.title', 'warehouses.name')
            ->selectRaw('inventories.id, inventories.quantity, products.title, warehouses.name, COUNT(iv.id) AS rows_count, COALESCE(SUM(iv.quantity), 0) AS assigned, COALESCE(SUM(CASE WHEN iv.quantity < 0 THEN 1 ELSE 0 END), 0) AS negatives')
            // Solo productos con stock por variante; un total en negativo sin variantes es cosa de inventory:audit
            ->havingRaw('(rows_count > 0 AND assigned > inventories.quantity) OR negatives > 0')
            ->get();

        foreach ($rows as $row) {
            $this->warn("{$row->name} · {$row->title}: total {$row->quantity}, repartido por variante {$row->assigned}"
                . ($row->negatives ? ", {$row->negatives} variante(s) en negativo" : ''));
        }
        $this->info($rows->isEmpty() ? 'El stock por variante cuadra con los totales.' : "{$rows->count()} inventarios para revisar en el conteo.");
    }
}
