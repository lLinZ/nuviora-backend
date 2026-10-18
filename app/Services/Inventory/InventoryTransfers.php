<?php

namespace App\Services\Inventory;

use App\Models\InventoryAuditLog;
use App\Models\InventoryPurchase;
use App\Models\InventoryTransfer;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Traslados con tránsito (Módulo 5 de Fran, §15, §92, §124, §126, R2).
 * - Al salir, cada línea pasa del origen al almacén virtual "En tránsito": ya no está disponible en el origen y todavía
 *   no está en el destino. El forecast la ve una sola vez, como "en tránsito hacia el destino" (§15, §124).
 * - Al recibir (lo marca el Admin, R2), cada línea entra al destino con las capas exactas que creó su salida, así
 *   conserva su lote, su costo y su fecha de compra (§126).
 * - Al cancelar uno que ya salió, vuelve al origen de la misma forma. Uno que no salió se cancela sin mover nada.
 * - Pendiente y Preparando (§92) son para los traslados que vengan del plan aprobado (entrega 4); el de la pantalla de
 *   traslados sale en el momento (R2: "al registrarlo, la mercancía queda En tránsito").
 * - Si depende de una compra (§58, §92), no puede salir hasta que esa compra se reciba.
 */
class InventoryTransfers
{
    public function __construct(private InventoryService $inventory)
    {
    }

    /**
     * @param array<int, array{product_id:int, variant_id?:?int, quantity:int}> $lines
     */
    public function create(int $fromWarehouseId, int $toWarehouseId, array $lines, ?string $notes = null, string $status = InventoryTransfer::IN_TRANSIT, ?int $purchaseId = null): InventoryTransfer
    {
        if (!in_array($status, [InventoryTransfer::PENDING, InventoryTransfer::IN_TRANSIT], true)) {
            throw ValidationException::withMessages(['status' => 'Un traslado nace pendiente o en tránsito.']);
        }
        $transit = Warehouse::transitId();
        if ($fromWarehouseId === $toWarehouseId) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'El origen y el destino tienen que ser distintos.']);
        }
        if (in_array($transit, [$fromWarehouseId, $toWarehouseId], true)) {
            throw ValidationException::withMessages(['to_warehouse_id' => '"En tránsito" no es un origen ni un destino: es donde queda lo que va en camino.']);
        }
        foreach ([$fromWarehouseId, $toWarehouseId] as $id) {
            Warehouse::active()->findOrFail($id);
        }
        $merged = $this->mergeLines($lines);
        if (!$merged) {
            throw ValidationException::withMessages(['lines' => 'El traslado no tiene productos.']);
        }

        return DB::transaction(function () use ($fromWarehouseId, $toWarehouseId, $merged, $notes, $status, $purchaseId) {
            $transfer = InventoryTransfer::create([
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'status' => InventoryTransfer::PENDING,
                'purchase_id' => $purchaseId,
                'notes' => $notes !== null ? mb_substr($notes, 0, 500) : null,
                'created_by' => auth()->id(),
            ]);
            foreach ($merged as $line) {
                $transfer->lines()->create($line);
            }
            InventoryAuditLog::record('transfer_created', 'inventory_transfer', $transfer->id, null, $this->fresh($transfer)->snapshot());

            return $status === InventoryTransfer::IN_TRANSIT ? $this->dispatch($transfer) : $this->fresh($transfer);
        });
    }

    /** Pendiente → Preparando (§92). No mueve stock. */
    public function prepare(InventoryTransfer $transfer): InventoryTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = $this->locked($transfer, [InventoryTransfer::PENDING], 'Solo se prepara un traslado pendiente.');
            $before = $transfer->snapshot();
            $transfer->update(['status' => InventoryTransfer::PREPARING]);
            InventoryAuditLog::record('transfer_preparing', 'inventory_transfer', $transfer->id, $before, $this->fresh($transfer)->snapshot());

            return $this->fresh($transfer);
        });
    }

    /** Sale del origen: cada línea pasa a "En tránsito" (§15). */
    public function dispatch(InventoryTransfer $transfer): InventoryTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = $this->locked($transfer, InventoryTransfer::NOT_DISPATCHED, 'Este traslado ya salió o se canceló.');
            if ($transfer->purchase_id) {
                $purchase = InventoryPurchase::find($transfer->purchase_id);
                if ($purchase && $purchase->status !== InventoryPurchase::RECEIVED) {
                    throw ValidationException::withMessages(['purchase' => 'Requiere recepción de proveedor antes de ejecutar: la compra todavía no llega (§58).']);
                }
            }
            $before = $transfer->snapshot();
            $transit = Warehouse::transitId();
            $notes = $this->movementNotes($transfer, 'Sale hacia');
            foreach ($transfer->lines as $line) {
                $movement = $this->inventory->transitMove((int) $line->product_id, (int) $transfer->from_warehouse_id, $transit, (int) $line->quantity,
                    $line->variant_id ? (int) $line->variant_id : null, auth()->id(), $notes,
                    ['reference_type' => InventoryTransfer::REFERENCE, 'reference_id' => $transfer->id]);
                $line->update(['dispatch_movement_id' => $movement->id]);
            }
            $transfer->update(['status' => InventoryTransfer::IN_TRANSIT, 'dispatched_at' => now(), 'dispatched_by' => auth()->id()]);
            InventoryAuditLog::record('transfer_dispatched', 'inventory_transfer', $transfer->id, $before, $this->fresh($transfer)->snapshot());

            return $this->fresh($transfer);
        });
    }

    /** Llegó: cada línea entra al destino con las capas de su salida (§126). Lo marca el Admin (R2). */
    public function receive(InventoryTransfer $transfer): InventoryTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = $this->locked($transfer, [InventoryTransfer::IN_TRANSIT], 'Solo se recibe un traslado que está en tránsito.');
            $before = $transfer->snapshot();
            $this->fromTransit($transfer, (int) $transfer->to_warehouse_id, 'Recibido en', 'receive_movement_id');
            $transfer->update(['status' => InventoryTransfer::RECEIVED, 'received_at' => now(), 'received_by' => auth()->id()]);
            InventoryAuditLog::record('transfer_received', 'inventory_transfer', $transfer->id, $before, $this->fresh($transfer)->snapshot());

            return $this->fresh($transfer);
        });
    }

    /** Cancelar: si ya salió, la mercancía vuelve al origen; si no, no se mueve nada. Un traslado recibido no se cancela. */
    public function cancel(InventoryTransfer $transfer, string $reason): InventoryTransfer
    {
        return DB::transaction(function () use ($transfer, $reason) {
            $transfer = $this->locked($transfer, [...InventoryTransfer::NOT_DISPATCHED, InventoryTransfer::IN_TRANSIT],
                'Este traslado ya se recibió o ya estaba cancelado.');
            $before = $transfer->snapshot();
            if ($transfer->status === InventoryTransfer::IN_TRANSIT) {
                $this->fromTransit($transfer, (int) $transfer->from_warehouse_id, 'Cancelado, vuelve a', 'receive_movement_id');
            }
            $transfer->update(['status' => InventoryTransfer::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => auth()->id(),
                'cancel_reason' => mb_substr($reason, 0, 500)]);
            InventoryAuditLog::record('transfer_cancelled', 'inventory_transfer', $transfer->id, $before, $this->fresh($transfer)->snapshot(), $reason);

            return $this->fresh($transfer);
        });
    }

    /**
     * Las líneas de un traslado desde un desglose por variante del diálogo de hoy ({variant_id: cantidad} o por nombre de
     * talla): lo que no se reparte va "sin variante".
     *
     * @return array<int, array{product_id:int, variant_id:?int, quantity:int}>
     */
    public function linesFor(int $productId, int $quantity, $variants): array
    {
        $split = $this->inventory->variantSplit($productId, $variants, $quantity);
        $lines = [];
        foreach ($split as $variantId => $qty) {
            $lines[] = ['product_id' => $productId, 'variant_id' => (int) $variantId, 'quantity' => (int) $qty];
        }
        $rest = $quantity - array_sum($split);
        if ($rest > 0) {
            $lines[] = ['product_id' => $productId, 'variant_id' => null, 'quantity' => $rest];
        }

        return $lines;
    }

    // ---------------------------------------------------------------------------------------------------------------

    /** Saca de "En tránsito" cada línea hacia $warehouseId, con las capas que creó su salida. */
    private function fromTransit(InventoryTransfer $transfer, int $warehouseId, string $verb, string $column): void
    {
        $transit = Warehouse::transitId();
        $notes = $this->movementNotes($transfer, $verb, $warehouseId);
        foreach ($transfer->lines as $line) {
            $movement = $this->inventory->transitMove((int) $line->product_id, $transit, $warehouseId, (int) $line->quantity,
                $line->variant_id ? (int) $line->variant_id : null, auth()->id(), $notes,
                ['reference_type' => InventoryTransfer::REFERENCE, 'reference_id' => $transfer->id,
                    'details' => ['dispatch_movement_id' => $line->dispatch_movement_id, 'transfer_line_id' => $line->id]]);
            $line->update([$column => $movement->id]);
        }
    }

    private function locked(InventoryTransfer $transfer, array $allowed, string $message): InventoryTransfer
    {
        $transfer = InventoryTransfer::lockForUpdate()->findOrFail($transfer->id);
        if (!in_array($transfer->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }

        return $transfer->load(['lines.product', 'lines.variant', 'fromWarehouse', 'toWarehouse']);
    }

    private function fresh(InventoryTransfer $transfer): InventoryTransfer
    {
        return $transfer->fresh(['lines.product', 'lines.variant', 'fromWarehouse', 'toWarehouse', 'creator']);
    }

    private function movementNotes(InventoryTransfer $transfer, string $verb, ?int $warehouseId = null): string
    {
        $name = Warehouse::whereKey($warehouseId ?? $transfer->to_warehouse_id)->value('name');
        $text = "Traslado #{$transfer->id}: {$verb} {$name}";

        return $transfer->notes ? "{$text} · {$transfer->notes}" : $text;
    }

    /**
     * Junta las líneas repetidas (mismo producto y variante) y descarta las vacías.
     *
     * @return array<int, array{product_id:int, variant_id:?int, quantity:int}>
     */
    private function mergeLines(array $lines): array
    {
        $merged = [];
        foreach ($lines as $line) {
            $qty = (int) ($line['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $variantId = !empty($line['variant_id']) ? (int) $line['variant_id'] : null;
            $key = (int) $line['product_id'] . '|' . ($variantId ?? 0);
            $merged[$key] ??= ['product_id' => (int) $line['product_id'], 'variant_id' => $variantId, 'quantity' => 0];
            $merged[$key]['quantity'] += $qty;
        }

        return array_values($merged);
    }
}
