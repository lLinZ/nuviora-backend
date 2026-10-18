<?php

namespace App\Services\Finance;

use App\Constants\OrderStatus;
use App\Http\Controllers\DefectiveStockController;
use App\Models\InventoryLot;
use App\Models\InventoryLotAllocation;
use App\Models\InventoryLotLayer;
use App\Models\InventoryMovement;
use App\Models\InventoryPurchase;
use App\Models\InventoryTransferTrip;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Inventory\OrderStock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Libro de lotes del Módulo 4 de Fran (entrega 2a; §9, §11, §12, R3, R5, R7, R8). Reproduce inventory_movements, en
 * orden, sobre los lotes: no va dentro de la transacción del movimiento, así un error aquí nunca frena una venta.
 *
 * - Corte (initialize): cada almacén + producto + variante con stock recibe un lote "initial" a products.cost_usd (R5);
 *   sin costo, queda "sin costo" para completarlo a mano. Las piezas defectuosas del corte quedan en revisión, en un
 *   lote "recovered" a $0. Lo que las órdenes abiertas tienen fuera también recibe su parte del lote inicial, para que
 *   al entregarse tengan lote.
 * - Cada movimiento nuevo (process), según su tipo:
 *   - out: sale FIFO de las capas disponibles de ese almacén + producto + variante, de la más vieja a la más nueva (§11);
 *     con variante, primero las de su variante y después las "sin variante", como el stock de hoy. Lo que no alcanza
 *     sale sin lote (el stock quedó en negativo).
 *   - in de una orden (reingreso): vuelven las capas que esa orden tenía, empezando por las últimas que tomó.
 *   - in de una compra (2b): lote "purchase" con el costo unitario de la compra (§9), uno por variante (R7).
 *   - in de una pieza retirada en un cambio: lote "recovered" a $0 y en revisión (decisión nuestra, confirmar con Fran).
 *   - otros in: lote "pending_cost" a cost_usd, que el Admin completa.
 *   - transfer: sale FIFO del origen y llega al destino con el mismo lote y su costo (§12), en una capa nueva. Si va en
 *     un viaje (2c), la capa suma a su logística la parte del viaje por unidad (§14, R8); recomputeLanded() la rehace
 *     cuando el viaje se registra, se corrige o se anula después.
 *   - adjustment: es una corrección de conteo (Fran, 2026-10-14). La diferencia de cada variante entra a un lote
 *     "adjustment" al costo del lote más reciente, o sale FIFO; no es merma. La revisión de una pieza defectuosa
 *     ("vuelve a la venta") pasa unidades de revisión a disponible, y "Pasar a revisión" (R8, 2d) de disponible a
 *     revisión, siempre con su lote y su costo.
 *   - out de una pieza en revisión ("Dar de baja"): sale de las capas en revisión; su costo es merma (Shrinkage).
 * - Cada movimiento es determinista: replay() lo rehace todo desde el corte con el mismo resultado.
 */
class LotLedger
{
    public const CUTOFF_KEY = 'finance.lots_cutoff_movement_id';
    public const LAST_KEY = 'finance.lots_last_movement_id';
    public const STARTED_KEY = 'finance.lots_started_at';

    /** El movimiento más nuevo que se procesa tiene que tener al menos esto: así no se salta uno que aún no hizo commit. */
    public const DEFAULT_LAG_SECONDS = 30;

    public static function initialized(): bool
    {
        return Setting::get(self::CUTOFF_KEY) !== null;
    }

    public static function lastProcessedId(): int
    {
        return (int) Setting::get(self::LAST_KEY, 0);
    }

    /**
     * Toma el corte. Bloquea inventories mientras lee, para que ningún movimiento quede a medias entre el stock leído y
     * el último id. Devuelve un resumen.
     *
     * @return array{cutoff:int, lots:int, layers:int, units:int, review:int, held:int, without_cost:int}
     */
    public function initialize(): array
    {
        if (self::initialized()) {
            throw new RuntimeException('El libro de lotes ya tiene corte. Para rehacerlo usa --replay.');
        }

        return DB::transaction(function () {
            $inventories = DB::table('inventories')->lockForUpdate()->orderBy('id')
                ->get(['warehouse_id', 'product_id', 'quantity', 'defective_stock']);
            $cutoff = (int) DB::table('inventory_movements')->max('id');
            $now = now();
            $variants = DB::table('inventory_variants')->get(['warehouse_id', 'product_id', 'variant_id', 'quantity', 'defective_stock'])
                ->groupBy(fn ($r) => "{$r->warehouse_id}|{$r->product_id}");
            $costs = DB::table('products')->pluck('cost_usd', 'id');

            // Lo que hay en cada almacén, por producto + variante: disponible y en revisión
            $stock = [];
            foreach ($inventories as $inv) {
                $rows = $variants["{$inv->warehouse_id}|{$inv->product_id}"] ?? collect();
                foreach ($rows as $iv) {
                    $this->addOpening($stock, (int) $inv->warehouse_id, (int) $inv->product_id, (int) $iv->variant_id, (int) $iv->quantity, (int) $iv->defective_stock);
                }
                $this->addOpening($stock, (int) $inv->warehouse_id, (int) $inv->product_id, null,
                    (int) $inv->quantity - (int) $rows->sum('quantity'), (int) $inv->defective_stock - (int) $rows->sum('defective_stock'));
            }

            // Lo que tienen fuera las órdenes que no se han entregado
            $held = $this->heldByOpenOrders();

            $lots = [];
            $summary = ['cutoff' => $cutoff, 'lots' => 0, 'layers' => 0, 'units' => 0, 'review' => 0, 'held' => 0, 'without_cost' => 0];
            $lotFor = function (int $productId, ?int $variantId, string $kind) use (&$lots, &$summary, $costs, $now) {
                $key = "{$productId}|" . ($variantId ?: 0) . "|{$kind}";
                if (!isset($lots[$key])) {
                    $cost = $kind === InventoryLot::KIND_RECOVERED ? 0.0 : $this->positiveCost($costs[$productId] ?? null);
                    $lots[$key] = InventoryLot::create([
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'kind' => $kind,
                        'unit_cost_usd' => $cost,
                        'cost_source' => $kind === InventoryLot::KIND_RECOVERED ? 'recovered' : ($cost !== null ? 'catalog' : null),
                        'received_at' => $now,
                        'notes' => $kind === InventoryLot::KIND_RECOVERED
                            ? 'Piezas defectuosas en el corte: en revisión, a $0'
                            : 'Lote inicial del corte (R5), a costo de catálogo',
                    ]);
                    $summary['lots']++;
                    if ($cost === null) {
                        $summary['without_cost']++;
                    }
                }

                return $lots[$key];
            };
            $layers = [];
            $layerFor = function (InventoryLot $lot, int $warehouseId, string $state) use (&$layers, &$summary) {
                $key = "{$lot->id}|{$warehouseId}|{$state}";
                if (!isset($layers[$key])) {
                    $layers[$key] = InventoryLotLayer::create([
                        'lot_id' => $lot->id, 'warehouse_id' => $warehouseId, 'product_id' => $lot->product_id,
                        'variant_id' => $lot->variant_id, 'state' => $state, 'quantity' => 0, 'opening_quantity' => 0,
                    ]);
                    $summary['layers']++;
                }

                return $layers[$key];
            };

            foreach ($stock as $s) {
                if ($s['available'] > 0) {
                    $lot = $lotFor($s['product_id'], $s['variant_id'], InventoryLot::KIND_INITIAL);
                    $layer = $layerFor($lot, $s['warehouse_id'], InventoryLotLayer::AVAILABLE);
                    $layer->quantity += $s['available'];
                    $layer->opening_quantity += $s['available'];
                    $layer->save();
                    $lot->increment('initial_quantity', $s['available']);
                    $summary['units'] += $s['available'];
                }
                if ($s['review'] > 0) {
                    $lot = $lotFor($s['product_id'], $s['variant_id'], InventoryLot::KIND_RECOVERED);
                    $layer = $layerFor($lot, $s['warehouse_id'], InventoryLotLayer::REVIEW);
                    $layer->quantity += $s['review'];
                    $layer->opening_quantity += $s['review'];
                    $layer->save();
                    $lot->increment('initial_quantity', $s['review']);
                    $summary['review'] += $s['review'];
                }
            }

            foreach ($held as $h) {
                $lot = $lotFor($h['product_id'], $h['variant_id'], InventoryLot::KIND_INITIAL);
                $layer = $layerFor($lot, $h['warehouse_id'], InventoryLotLayer::AVAILABLE);
                $lot->increment('initial_quantity', $h['quantity']);
                InventoryLotAllocation::create([
                    'inventory_movement_id' => $h['movement_id'],
                    'layer_id' => $layer->id,
                    'warehouse_id' => $h['warehouse_id'],
                    'product_id' => $h['product_id'],
                    'variant_id' => $h['variant_id'],
                    'quantity' => -$h['quantity'],
                    'is_opening' => true,
                    'reference_type' => OrderStock::REFERENCE,
                    'reference_id' => $h['order_id'],
                ]);
                $summary['held'] += $h['quantity'];
            }

            Setting::set(self::CUTOFF_KEY, $cutoff);
            Setting::set(self::LAST_KEY, $cutoff);
            Setting::set(self::STARTED_KEY, $now->toDateTimeString());

            return $summary;
        });
    }

    /**
     * Procesa los movimientos nuevos, en orden de id. Se detiene en el primero que sea demasiado reciente (puede haber
     * uno anterior sin commit todavía) o al pasar $maxSeconds. Devuelve cuántos procesó.
     */
    public function process(int $lagSeconds = self::DEFAULT_LAG_SECONDS, int $maxSeconds = 50): int
    {
        if (!self::initialized()) {
            return 0;
        }
        $started = microtime(true);
        $limit = now()->subSeconds($lagSeconds);
        $last = self::lastProcessedId();
        $done = 0;
        $orders = [];

        try {
            while (microtime(true) - $started < $maxSeconds) {
                $batch = InventoryMovement::where('id', '>', $last)->orderBy('id')->limit(200)->get();
                if ($batch->isEmpty()) {
                    break;
                }
                foreach ($batch as $movement) {
                    if ($lagSeconds > 0 && $movement->created_at && $movement->created_at->gt($limit)) {
                        return $done;
                    }
                    DB::transaction(function () use ($movement) {
                        if ($movement->status === 'completed') {
                            $this->apply($movement);
                        }
                        Setting::set(self::LAST_KEY, $movement->id);
                    });
                    if ($movement->reference_type === OrderStock::REFERENCE && $movement->reference_id) {
                        $orders[] = (int) $movement->reference_id;
                    }
                    $last = $movement->id;
                    $done++;
                    if (microtime(true) - $started >= $maxSeconds) {
                        return $done;
                    }
                }
            }

            return $done;
        } finally {
            // Las órdenes que tomaron o devolvieron lotes cambian de costo (entrega 2e): se recalculan
            OrderEconomics::markStale($orders);
        }
    }

    /**
     * Rehace el libro desde el corte: borra lo que hicieron los movimientos, devuelve cada capa a su cantidad del corte
     * y los vuelve a procesar. Los lotes que nacieron de un movimiento se conservan (con su costo, si el Admin lo
     * completó) y se reutilizan.
     */
    public function replay(int $maxSeconds = 3600): int
    {
        if (!self::initialized()) {
            throw new RuntimeException('El libro de lotes todavía no tiene corte.');
        }
        DB::transaction(function () {
            InventoryLotAllocation::where('is_opening', false)->delete();
            InventoryLotLayer::whereNotNull('created_by_movement_id')->delete();
            DB::table('inventory_lot_layers')->update(['quantity' => DB::raw('opening_quantity')]);
            Setting::set(self::LAST_KEY, (int) Setting::get(self::CUTOFF_KEY));
        });
        $done = $this->process(0, $maxSeconds);
        // Todas las órdenes con lotes pueden haber cambiado de capas
        OrderEconomics::markStaleWhere(DB::table('inventory_lot_allocations')->where('reference_type', OrderStock::REFERENCE)
            ->whereNotNull('reference_id')->select('reference_id'));

        return $done;
    }

    /**
     * Compara las capas con el stock, por almacén + producto + variante ("sin variante" = el total menos lo repartido).
     *
     * @return array{rows: array<int, array>, pending:int}
     */
    public function check(): array
    {
        $lots = DB::table('inventory_lot_layers')
            ->groupBy('warehouse_id', 'product_id', 'variant_id')
            ->selectRaw("warehouse_id, product_id, variant_id, SUM(quantity) AS qty, SUM(CASE WHEN state = 'review' THEN quantity ELSE 0 END) AS review")
            ->get()
            ->keyBy(fn ($r) => "{$r->warehouse_id}|{$r->product_id}|" . ($r->variant_id ?: 0));

        $stock = [];
        $variants = DB::table('inventory_variants')->get(['warehouse_id', 'product_id', 'variant_id', 'quantity', 'defective_stock'])
            ->groupBy(fn ($r) => "{$r->warehouse_id}|{$r->product_id}");
        foreach (DB::table('inventories')->get(['warehouse_id', 'product_id', 'quantity', 'defective_stock']) as $inv) {
            $rows = $variants["{$inv->warehouse_id}|{$inv->product_id}"] ?? collect();
            foreach ($rows as $iv) {
                $stock["{$inv->warehouse_id}|{$inv->product_id}|{$iv->variant_id}"] = [(int) $iv->quantity, (int) $iv->defective_stock];
            }
            $stock["{$inv->warehouse_id}|{$inv->product_id}|0"] = [
                (int) $inv->quantity - (int) $rows->sum('quantity'),
                (int) $inv->defective_stock - (int) $rows->sum('defective_stock'),
            ];
        }

        $rows = [];
        foreach (array_unique(array_merge(array_keys($stock), $lots->keys()->all())) as $key) {
            [$qty, $defective] = $stock[$key] ?? [0, 0];
            $inLots = (int) ($lots[$key]->qty ?? 0);
            $inReview = (int) ($lots[$key]->review ?? 0);
            // El stock en negativo no tiene lote: se compara con 0
            if (max(0, $qty) !== $inLots || max(0, min($defective, $qty)) !== $inReview) {
                [$warehouseId, $productId, $variantId] = array_map('intval', explode('|', $key));
                $rows[] = [
                    'warehouse_id' => $warehouseId, 'product_id' => $productId, 'variant_id' => $variantId ?: null,
                    'stock' => $qty, 'lots' => $inLots, 'defective' => $defective, 'review' => $inReview,
                ];
            }
        }

        $pending = self::initialized() ? InventoryMovement::where('id', '>', self::lastProcessedId())->count() : 0;

        return ['rows' => $rows, 'pending' => $pending];
    }

    /**
     * Rehace la logística por unidad de las capas (§14), después de registrar, corregir o anular un viaje: cada capa que
     * llegó por un traslado tiene la logística de su origen más la parte de su viaje. Las capas del corte no cambian.
     * Recorre las capas por id: el origen siempre es anterior. Devuelve cuántas cambiaron.
     */
    public function recomputeLanded(): int
    {
        $tripCost = InventoryTransferTrip::whereNull('voided_at')->pluck('unit_cost_usd', 'id');
        $movementTrip = InventoryMovement::whereNotNull('trip_id')->where('movement_type', 'transfer')->pluck('trip_id', 'id');
        $landed = [];
        $changed = [];
        InventoryLotLayer::select('id', 'parent_layer_id', 'created_by_movement_id', 'landed_cost_per_unit')
            ->chunkById(2000, function ($layers) use ($tripCost, $movementTrip, &$landed, &$changed) {
                foreach ($layers as $layer) {
                    if (!$layer->created_by_movement_id) {
                        $landed[$layer->id] = (float) $layer->landed_cost_per_unit;
                        continue;
                    }
                    $trip = $movementTrip[$layer->created_by_movement_id] ?? null;
                    $value = round(($layer->parent_layer_id ? ($landed[$layer->parent_layer_id] ?? 0.0) : 0.0)
                        + ($trip !== null ? (float) ($tripCost[$trip] ?? 0) : 0.0), 4);
                    $landed[$layer->id] = $value;
                    if (abs($value - (float) $layer->landed_cost_per_unit) > 0.00005) {
                        DB::table('inventory_lot_layers')->where('id', $layer->id)->update(['landed_cost_per_unit' => $value, 'updated_at' => now()]);
                        $changed[] = $layer->id;
                    }
                }
            });
        // Las órdenes que tomaron unidades de esas capas cambian de logística (entrega 2e)
        foreach (array_chunk($changed, 1000) as $ids) {
            OrderEconomics::markStaleWhere(DB::table('inventory_lot_allocations')->where('reference_type', OrderStock::REFERENCE)
                ->whereIn('layer_id', $ids)->whereNotNull('reference_id')->select('reference_id'));
        }

        return count($changed);
    }

    /** Lo que una orden tiene fuera, por capa (las unidades sin lote van con layer_id vacío). */
    public function heldByOrder(int $orderId): Collection
    {
        return InventoryLotAllocation::where('reference_type', OrderStock::REFERENCE)->where('reference_id', $orderId)
            ->groupBy('layer_id', 'warehouse_id', 'product_id', 'variant_id')
            ->selectRaw('layer_id, warehouse_id, product_id, variant_id, -SUM(quantity) AS held, MAX(id) AS last_id')
            ->havingRaw('SUM(quantity) <> 0')
            ->get();
    }

    // ---------------------------------------------------------------------------------------------------------------

    private function apply(InventoryMovement $m): void
    {
        $qty = (int) $m->quantity;
        $variantId = $m->variant_id ? (int) $m->variant_id : null;

        switch ($m->movement_type) {
            case 'out':
                if (!$m->from_warehouse_id || $qty <= 0) {
                    return;
                }
                $state = $m->reference_type === DefectiveStockController::REFERENCE ? InventoryLotLayer::REVIEW : InventoryLotLayer::AVAILABLE;
                $this->take($m, (int) $m->from_warehouse_id, $variantId, $qty, $state);
                return;

            case 'in':
                if (!$m->to_warehouse_id || $qty <= 0) {
                    return;
                }
                if ($m->reference_type === OrderStock::REFERENCE) {
                    $this->returnFromOrder($m, $qty);
                } elseif ($m->reference_type === InventoryPurchase::REFERENCE && $m->reference_id) {
                    $this->newLot($m, (int) $m->to_warehouse_id, $variantId, $qty, InventoryLot::KIND_PURCHASE, InventoryLotLayer::AVAILABLE);
                } elseif ($m->reference_type === OrderStock::DEFECTIVE_REFERENCE) {
                    $this->newLot($m, (int) $m->to_warehouse_id, $variantId, $qty, InventoryLot::KIND_RECOVERED, InventoryLotLayer::REVIEW);
                } else {
                    $this->newLot($m, (int) $m->to_warehouse_id, $variantId, $qty, InventoryLot::KIND_PENDING_COST, InventoryLotLayer::AVAILABLE);
                }
                return;

            case 'transfer':
                if (!$m->from_warehouse_id || !$m->to_warehouse_id || $qty <= 0) {
                    return;
                }
                // Módulo 5 (§126): al recibir un traslado, salen de "En tránsito" exactamente las capas que creó su salida, así
                // dos traslados del mismo producto en camino no se cruzan los lotes. Lo que falte, FIFO como siempre.
                $taken = [];
                $dispatch = (int) ($m->details['dispatch_movement_id'] ?? 0);
                if ($dispatch) {
                    $taken = $this->take($m, (int) $m->from_warehouse_id, $variantId, $qty, InventoryLotLayer::AVAILABLE, $dispatch);
                }
                $rest = $qty - array_sum(array_column($taken, 1));
                if ($rest > 0) {
                    $taken = array_merge($taken, $this->take($m, (int) $m->from_warehouse_id, $variantId, $rest, InventoryLotLayer::AVAILABLE));
                }
                foreach ($taken as [$layer, $n]) {
                    $this->putInNewLayer($m, $layer, (int) $m->to_warehouse_id, InventoryLotLayer::AVAILABLE, $n);
                }
                return;

            case 'adjustment':
                if (!$m->to_warehouse_id) {
                    return;
                }
                if ($m->reference_type === DefectiveStockController::REFERENCE) {
                    // "Vuelve a la venta": de revisión a disponible, en el mismo almacén y con el mismo lote
                    foreach ($this->take($m, (int) $m->to_warehouse_id, $variantId, $qty, InventoryLotLayer::REVIEW) as [$layer, $n]) {
                        $this->putInNewLayer($m, $layer, (int) $m->to_warehouse_id, InventoryLotLayer::AVAILABLE, $n);
                    }
                    return;
                }
                if ($m->reference_type === DefectiveStockController::INTAKE_REFERENCE) {
                    // R8: dañada o perdida, pasa a revisión con su lote y su costo (FIFO); si se da de baja, es merma
                    foreach ($this->take($m, (int) $m->to_warehouse_id, $variantId, $qty, InventoryLotLayer::AVAILABLE) as [$layer, $n]) {
                        $this->putInNewLayer($m, $layer, (int) $m->to_warehouse_id, InventoryLotLayer::REVIEW, $n);
                    }
                    return;
                }
                // Un ajuste es una corrección de conteo, no una compra ni una pérdida (Fran, 2026-10-14): las unidades entran
                // o salen de los lotes para que el inventario cuadre con lo físico, y su costo no va al P&L como merma
                foreach ($this->adjustmentDeltas($m) as [$bucketVariant, $delta]) {
                    if ($delta > 0) {
                        $this->newLot($m, (int) $m->to_warehouse_id, $bucketVariant, $delta, InventoryLot::KIND_ADJUSTMENT, InventoryLotLayer::AVAILABLE);
                    } elseif ($delta < 0) {
                        $this->take($m, (int) $m->to_warehouse_id, $bucketVariant, -$delta, InventoryLotLayer::AVAILABLE);
                    }
                }
                return;
        }
    }

    /**
     * Saca $qty unidades FIFO de las capas de ese almacén + producto + variante en ese estado. Con variante, primero las
     * de su variante y después las "sin variante". Lo que no alcanza queda como salida sin lote. Devuelve [capa, n].
     * Con $createdBy, solo de las capas que creó ese movimiento, y lo que no alcanza no se registra (lo saca quien llama).
     *
     * @return array<int, array{0: InventoryLotLayer, 1: int}>
     */
    private function take(InventoryMovement $m, int $warehouseId, ?int $variantId, int $qty, string $state, ?int $createdBy = null): array
    {
        $taken = [];
        $groups = $variantId ? [$variantId, null] : [null];
        foreach ($groups as $variant) {
            if ($qty <= 0) {
                break;
            }
            $layers = InventoryLotLayer::query()
                ->join('inventory_lots', 'inventory_lots.id', '=', 'inventory_lot_layers.lot_id')
                ->where('inventory_lot_layers.warehouse_id', $warehouseId)
                ->where('inventory_lot_layers.product_id', $m->product_id)
                ->where('inventory_lot_layers.state', $state)
                ->where('inventory_lot_layers.quantity', '>', 0)
                ->when($createdBy, fn ($q) => $q->where('inventory_lot_layers.created_by_movement_id', $createdBy))
                ->when($variant, fn ($q) => $q->where('inventory_lot_layers.variant_id', $variant), fn ($q) => $q->whereNull('inventory_lot_layers.variant_id'))
                ->orderBy('inventory_lots.received_at')->orderBy('inventory_lots.id')->orderBy('inventory_lot_layers.id')
                ->select('inventory_lot_layers.*')
                ->get();
            foreach ($layers as $layer) {
                $n = min($qty, $layer->quantity);
                $layer->decrement('quantity', $n);
                $this->allocate($m, $layer, $warehouseId, $variantId, -$n);
                $taken[] = [$layer, $n];
                $qty -= $n;
                if ($qty <= 0) {
                    break;
                }
            }
        }
        if ($qty > 0 && !$createdBy) {
            $this->allocate($m, null, $warehouseId, $variantId, -$qty);
        }

        return $taken;
    }

    /** Reingreso de una orden: vuelven sus capas, empezando por las últimas que tomó. */
    private function returnFromOrder(InventoryMovement $m, int $qty): void
    {
        $warehouseId = (int) $m->to_warehouse_id;
        $variantId = $m->variant_id ? (int) $m->variant_id : null;
        $held = $this->heldByOrder((int) $m->reference_id)
            ->where('product_id', $m->product_id)
            ->filter(fn ($h) => (int) $h->held > 0)
            ->sortBy(fn ($h) => [
                (int) $h->warehouse_id === $warehouseId ? 0 : 1,          // primero lo que salió de este almacén
                ($h->variant_id ? (int) $h->variant_id : null) === $variantId ? 0 : 1,
                -(int) $h->last_id,                                          // y lo último que tomó
            ]);

        foreach ($held as $h) {
            if ($qty <= 0) {
                break;
            }
            $n = min($qty, (int) $h->held);
            if (!$h->layer_id) {
                // Había salido sin lote: vuelve sin lote
                $this->allocate($m, null, $warehouseId, $variantId, $n);
            } else {
                $layer = InventoryLotLayer::find($h->layer_id);
                if ((int) $layer->warehouse_id === $warehouseId && $layer->state === InventoryLotLayer::AVAILABLE) {
                    $layer->increment('quantity', $n);
                    $this->allocate($m, $layer, $warehouseId, $variantId, $n);
                } else {
                    $this->putInNewLayer($m, $layer, $warehouseId, InventoryLotLayer::AVAILABLE, $n, $variantId);
                }
            }
            $qty -= $n;
        }

        if ($qty > 0) {
            // Salió antes del corte (una orden ya entregada que vuelve): entra a costo de catálogo, como el lote inicial
            $this->newLot($m, $warehouseId, $variantId, $qty, InventoryLot::KIND_INITIAL, InventoryLotLayer::AVAILABLE);
        }
    }

    /** Crea (o reutiliza en un --replay) el lote que nace de este movimiento, con su capa. */
    private function newLot(InventoryMovement $m, int $warehouseId, ?int $variantId, int $qty, string $kind, string $state): void
    {
        $lot = InventoryLot::where('source_movement_id', $m->id)->where('product_id', $m->product_id)
            ->where('variant_id', $variantId)->where('kind', $kind)->first();
        if (!$lot) {
            // Una compra (§9): el costo unitario de la compra, igual para cada variante (R7: el lote por variante ya permite
            // que una talla cueste distinto más adelante)
            $purchase = $kind === InventoryLot::KIND_PURCHASE ? InventoryPurchase::with('supplier:id,name')->find($m->reference_id) : null;
            $latest = $kind === InventoryLot::KIND_ADJUSTMENT ? $this->latestLotCost((int) $m->product_id, $variantId) : null;
            $cost = match (true) {
                $kind === InventoryLot::KIND_RECOVERED => 0.0,
                $purchase !== null => round((float) $purchase->unit_cost_usd, 4),
                $latest !== null => $latest,
                default => $this->positiveCost(DB::table('products')->where('id', $m->product_id)->value('cost_usd')),
            };
            $lot = InventoryLot::create([
                'product_id' => $m->product_id,
                'variant_id' => $variantId,
                'kind' => $kind,
                'purchase_id' => $purchase?->id,
                'source_movement_id' => $m->id,
                'initial_quantity' => $qty,
                'unit_cost_usd' => $cost,
                'cost_source' => match (true) {
                    $kind === InventoryLot::KIND_RECOVERED => 'recovered',
                    $purchase !== null => 'purchase',
                    $latest !== null => 'last_lot',
                    $cost === null => null,
                    default => 'catalog',
                },
                'received_at' => $m->created_at ?? now(),
                'notes' => match (true) {
                    $purchase !== null => 'Compra a ' . ($purchase->supplier?->name ?? 'proveedor'),
                    $kind === InventoryLot::KIND_RECOVERED => 'Pieza retirada en un cambio: en revisión, a $0 (su costo ya se contó en la orden)',
                    $kind === InventoryLot::KIND_INITIAL => 'Reingreso de una orden que salió antes del corte: a costo de catálogo',
                    $kind === InventoryLot::KIND_ADJUSTMENT => 'Corrección de conteo (ajuste): ' . ($latest !== null ? 'al costo del lote más reciente' : 'a costo de catálogo'),
                    default => 'Entró sin costo de compra: costo de catálogo provisional, por completar',
                },
            ]);
        }
        $layer = InventoryLotLayer::create([
            'lot_id' => $lot->id, 'warehouse_id' => $warehouseId, 'product_id' => $m->product_id, 'variant_id' => $variantId,
            'state' => $state, 'quantity' => $qty, 'created_by_movement_id' => $m->id,
        ]);
        $this->allocate($m, $layer, $warehouseId, $variantId, $qty);
    }

    /**
     * Pone $n unidades de $from (mismo lote y costo) en una capa nueva de ese almacén y estado. Su logística es la del
     * origen más, si es un traslado de un viaje, la parte del viaje por unidad (§14).
     */
    private function putInNewLayer(InventoryMovement $m, InventoryLotLayer $from, int $warehouseId, string $state, int $n, ?int $movementVariant = null): void
    {
        $layer = InventoryLotLayer::create([
            'lot_id' => $from->lot_id, 'warehouse_id' => $warehouseId, 'product_id' => $from->product_id,
            'variant_id' => $from->variant_id, 'state' => $state, 'quantity' => $n,
            'landed_cost_per_unit' => round($from->landed_cost_per_unit + $this->tripUnitCost($m), 4), 'parent_layer_id' => $from->id,
            'created_by_movement_id' => $m->id,
        ]);
        $this->allocate($m, $layer, $warehouseId, $movementVariant ?? ($m->variant_id ? (int) $m->variant_id : null), $n);
    }

    private function allocate(InventoryMovement $m, ?InventoryLotLayer $layer, int $warehouseId, ?int $variantId, int $quantity): void
    {
        InventoryLotAllocation::create([
            'inventory_movement_id' => $m->id,
            'layer_id' => $layer?->id,
            'warehouse_id' => $warehouseId,
            'product_id' => $m->product_id,
            'variant_id' => $variantId,
            'quantity' => $quantity,
            'reference_type' => $m->reference_type,
            'reference_id' => $m->reference_id,
        ]);
    }

    /**
     * Lo que cambió un ajuste, por variante ("sin variante" = el total menos lo repartido). Usa details; si un ajuste
     * no lo tiene, lee "(Old: X, New: Y)" de la nota y lo toma como "sin variante".
     *
     * @return array<int, array{0: ?int, 1: int}>
     */
    private function adjustmentDeltas(InventoryMovement $m): array
    {
        $details = $m->details;
        if (is_array($details) && isset($details['old'], $details['new'])) {
            $total = (int) $details['new'] - (int) $details['old'];
            $deltas = [];
            $assigned = 0;
            foreach ((array) ($details['variants'] ?? []) as $variantId => [$before, $after]) {
                $d = (int) $after - (int) $before;
                $deltas[] = [(int) $variantId, $d];
                $assigned += $d;
            }
            $deltas[] = [null, $total - $assigned];

            return $deltas;
        }
        if (preg_match('/Old:\s*(-?\d+),\s*New:\s*(-?\d+)/', (string) $m->notes, $hit)) {
            return [[null, (int) $hit[2] - (int) $hit[1]]];
        }

        return [];
    }

    private function addOpening(array &$stock, int $warehouseId, int $productId, ?int $variantId, int $quantity, int $defective): void
    {
        $review = max(0, min($defective, $quantity));
        $available = max(0, $quantity - $review);
        if ($available === 0 && $review === 0) {
            return;
        }
        $stock[] = ['warehouse_id' => $warehouseId, 'product_id' => $productId, 'variant_id' => $variantId, 'available' => $available, 'review' => $review];
    }

    /**
     * Lo que tienen fuera las órdenes no entregadas, con el último movimiento de salida de cada una.
     *
     * @return array<int, array{order_id:int, warehouse_id:int, product_id:int, variant_id:?int, quantity:int, movement_id:?int}>
     */
    private function heldByOpenOrders(): array
    {
        $delivered = DB::table('statuses')->where('description', OrderStatus::ENTREGADO)->value('id');
        $orderIds = DB::table('inventory_movements')
            ->where('reference_type', OrderStock::REFERENCE)->whereIn('movement_type', ['out', 'in'])
            ->groupBy('reference_id')
            ->havingRaw("SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE -quantity END) <> 0")
            ->pluck('reference_id');

        $stock = app(OrderStock::class);
        $held = [];
        foreach ($orderIds->chunk(500) as $chunk) {
            $orders = Order::whereIn('id', $chunk)->where(fn ($q) => $q->whereNull('status_id')->orWhere('status_id', '<>', $delivered))->get();
            foreach ($orders as $order) {
                $lastOut = DB::table('inventory_movements')->where('reference_type', OrderStock::REFERENCE)
                    ->where('reference_id', $order->id)->where('movement_type', 'out')
                    ->selectRaw('product_id, MAX(id) AS id')->groupBy('product_id')->pluck('id', 'product_id');
                foreach ($stock->held($order) as $h) {
                    if ($h['quantity'] <= 0) {
                        continue;
                    }
                    $held[] = $h + ['order_id' => $order->id, 'movement_id' => $lastOut[$h['product_id']] ?? null];
                }
            }
        }

        return $held;
    }

    /** La parte por unidad del viaje de un traslado (§14), o 0 si no va en un viaje o el viaje está anulado. */
    private function tripUnitCost(InventoryMovement $m): float
    {
        if ($m->movement_type !== 'transfer' || !$m->trip_id) {
            return 0.0;
        }

        return (float) (InventoryTransferTrip::whereKey($m->trip_id)->whereNull('voided_at')->value('unit_cost_usd') ?? 0);
    }

    /**
     * El costo del lote más reciente de ese producto (primero de esa misma variante, o "sin variante"), sin contar las
     * piezas recuperadas a $0 ni los lotes sin costo. Es el que recibe una corrección de conteo hacia arriba.
     */
    private function latestLotCost(int $productId, ?int $variantId): ?float
    {
        $query = fn () => InventoryLot::where('product_id', $productId)->where('kind', '<>', InventoryLot::KIND_RECOVERED)
            ->whereNotNull('unit_cost_usd')->where('unit_cost_usd', '>', 0)->orderByDesc('received_at')->orderByDesc('id');
        $cost = $query()->where('variant_id', $variantId)->value('unit_cost_usd');
        $cost ??= $query()->value('unit_cost_usd');

        return $cost !== null ? round((float) $cost, 4) : null;
    }

    private function positiveCost($cost): ?float
    {
        return $cost !== null && (float) $cost > 0 ? round((float) $cost, 4) : null;
    }
}
