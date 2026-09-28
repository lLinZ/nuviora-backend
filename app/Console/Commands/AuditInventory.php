<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mide el desfase del inventario (tarea 3a): rehace, movimiento por movimiento, lo que cada almacén
 * debería tener de cada producto y lo compara con lo que dice el sistema. Solo lee.
 *  - Entrada (in): suma en el destino. Salida (out): resta en el origen.
 *  - Traslado completado: resta en el origen y suma en el destino. Pendiente: solo resta en el origen.
 *  - Ajuste: fija la cantidad nueva (se lee de su nota "(Old: X, New: Y)").
 * Si un producto tuvo cambios que no dejaron movimiento (el sistema anterior), aparece como diferencia:
 * eso es justo lo que hay que revisar en el conteo físico.
 */
class AuditInventory extends Command
{
    protected $signature = 'inventory:audit {--warehouse= : Solo este almacén (id)} {--all : Mostrar también los que cuadran}';
    protected $description = 'Compara el inventario de cada almacén con la suma de sus movimientos (solo lectura)';

    public function handle(): int
    {
        $warehouseId = $this->option('warehouse') ? (int) $this->option('warehouse') : null;
        $expected = [];
        $unparsed = 0;

        DB::table('inventory_movements')->orderBy('created_at')->orderBy('id')
            ->select('id', 'product_id', 'from_warehouse_id', 'to_warehouse_id', 'quantity', 'movement_type', 'status', 'notes')
            ->chunk(2000, function ($rows) use (&$expected, &$unparsed) {
                foreach ($rows as $m) {
                    $q = (int) $m->quantity;
                    $from = $m->from_warehouse_id ? "{$m->from_warehouse_id}|{$m->product_id}" : null;
                    $to = $m->to_warehouse_id ? "{$m->to_warehouse_id}|{$m->product_id}" : null;
                    switch ($m->movement_type) {
                        case 'in':
                            if ($to) $expected[$to] = ($expected[$to] ?? 0) + $q;
                            break;
                        case 'out':
                            if ($from) $expected[$from] = ($expected[$from] ?? 0) - $q;
                            break;
                        case 'transfer':
                            if ($m->status === 'cancelled') break;
                            if ($from) $expected[$from] = ($expected[$from] ?? 0) - $q;
                            if ($to && $m->status !== 'pending') $expected[$to] = ($expected[$to] ?? 0) + $q;
                            break;
                        case 'adjustment':
                            if ($to && preg_match('/New:\s*(-?\d+)/', (string) $m->notes, $hit)) {
                                $expected[$to] = (int) $hit[1];
                            } else {
                                $unparsed++;
                            }
                            break;
                    }
                }
            });

        $inventories = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->when($warehouseId, fn ($q) => $q->where('inventories.warehouse_id', $warehouseId))
            ->orderBy('warehouses.name')->orderBy('products.title')
            ->get(['inventories.warehouse_id', 'inventories.product_id', 'inventories.quantity', 'inventories.defective_stock', 'warehouses.name as warehouse', 'products.title as product']);

        $rows = [];
        $off = 0;
        $units = 0;
        foreach ($inventories as $inv) {
            $key = "{$inv->warehouse_id}|{$inv->product_id}";
            $should = $expected[$key] ?? 0;
            $diff = (int) $inv->quantity - $should;
            if ($diff !== 0) {
                $off++;
                $units += abs($diff);
            }
            if ($diff !== 0 || $this->option('all')) {
                $rows[] = [$inv->warehouse, $inv->product, (int) $inv->quantity, $should, $diff > 0 ? "+{$diff}" : (string) $diff, (int) $inv->defective_stock];
            }
        }

        if ($rows) {
            $this->table(['Almacén', 'Producto', 'Sistema', 'Según movimientos', 'Diferencia', 'Defectuosas'], $rows);
        }
        $this->info("Revisados {$inventories->count()} inventarios: {$off} no cuadran ({$units} unidades de diferencia en total).");
        if ($unparsed) {
            $this->warn("{$unparsed} ajustes sin cantidad nueva en la nota no se pudieron rehacer.");
        }

        return self::SUCCESS;
    }
}
