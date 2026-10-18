<?php

namespace App\Services\Finance;

use App\Models\AccountPayable;
use App\Models\Expense;
use App\Models\FinancialAuditLog;
use App\Models\InventoryAuditLog;
use App\Models\InventoryLot;
use App\Models\InventoryPurchase;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ingreso de inventario con su compra (Módulo 4 de Fran, §9, §16, §17, R6): en el mismo paso entran las unidades al
 * almacén, queda la compra con su costo y nace la deuda con el proveedor. A crédito, la deuda queda abierta con su
 * vencimiento y condiciones (caso 5 del §108). Al contado, la deuda se crea y se paga entera en ese momento, así la caja
 * (entrega 3) tiene una sola fuente para todos los pagos a proveedores.
 * Costo unitario = costo total en USD / cantidad (§9). El libro de lotes crea los lotes con ese costo.
 *
 * Módulo 5 (§16, §90, §91, R3): la compra también puede registrarse al hacerla, "por recibir", con la fecha en que debería
 * llegar. No suma stock ni crea lote, deuda o pago. Al recibirla se ponen la cantidad y el costo reales, y ahí pasa lo de
 * siempre. Lo esperado se guarda aparte para compararlo después (§97).
 */
class InventoryPurchases
{
    public function __construct(private InventoryService $inventory, private Payables $payables)
    {
    }

    /**
     * Compra que ya llegó: entra al stock, con su lote y su deuda o su pago, en el mismo paso (Módulo 4).
     *
     * @param array{product_id:int, warehouse_id:int, quantity:int, variants?:mixed, notes?:?string, supplier_id:int,
     *              total_amount:float|string, currency:string, rate?:float|string|null, payment_type:string, due_date?:?string,
     *              terms?:?string, financial_account_id?:?int} $data
     */
    public function create(array $data): InventoryPurchase
    {
        $supplier = $this->activeSupplier((int) $data['supplier_id']);
        $quantity = (int) $data['quantity'];
        $total = round((float) $data['total_amount'], 2);
        $usd = Expense::toUsd($total, $data['currency'], isset($data['rate']) ? (float) $data['rate'] : null);
        $credit = $data['payment_type'] === 'credit';

        return DB::transaction(function () use ($data, $supplier, $quantity, $total, $usd, $credit) {
            $product = Product::findOrFail($data['product_id']);
            $purchase = InventoryPurchase::create([
                'supplier_id' => $supplier->id,
                'product_id' => $product->id,
                'warehouse_id' => $data['warehouse_id'],
                'quantity' => $quantity,
                'total_amount' => $total,
                'currency' => $data['currency'],
                'rate' => $usd['rate'],
                'total_usd' => $usd['amount_usd'],
                'unit_cost_usd' => round($usd['amount_usd'] / $quantity, 4),
                'payment_type' => $data['payment_type'],
                'purchased_at' => now(),
                'due_date' => $credit ? ($data['due_date'] ?? null) : null,
                'terms' => $credit ? ($data['terms'] ?? null) : null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
                'status' => InventoryPurchase::RECEIVED,
                'received_at' => now(),
            ]);

            $this->stockAndDebt($purchase, $supplier, $product, $data, $total, $usd, $credit);
            FinancialAuditLog::record('inventory_purchase_created', 'inventory_purchase', $purchase->id, null, $purchase->fresh()->snapshot());

            return $purchase->fresh(['supplier', 'product', 'warehouse']);
        });
    }

    /**
     * Compra por recibir (§16, R3): lo que se pidió, lo que debería costar y cuándo llega. No mueve nada.
     *
     * @param array{product_id:int, warehouse_id:int, quantity:int, variants?:mixed, notes?:?string, supplier_id:int,
     *              total_amount:float|string, currency:string, rate?:float|string|null, expected_at:string} $data
     */
    public function createPending(array $data): InventoryPurchase
    {
        $supplier = $this->activeSupplier((int) $data['supplier_id']);
        $quantity = (int) $data['quantity'];
        $total = round((float) $data['total_amount'], 2);
        $usd = Expense::toUsd($total, $data['currency'], isset($data['rate']) ? (float) $data['rate'] : null);
        $variants = $this->inventory->variantSplit((int) $data['product_id'], $data['variants'] ?? null, $quantity);

        return DB::transaction(function () use ($data, $supplier, $quantity, $total, $usd, $variants) {
            $product = Product::findOrFail($data['product_id']);
            $purchase = InventoryPurchase::create([
                'supplier_id' => $supplier->id,
                'product_id' => $product->id,
                'warehouse_id' => $data['warehouse_id'],
                'quantity' => $quantity,
                'total_amount' => $total,
                'currency' => $data['currency'],
                'rate' => $usd['rate'],
                'total_usd' => $usd['amount_usd'],
                'unit_cost_usd' => round($usd['amount_usd'] / $quantity, 4),
                'payment_type' => null,
                'purchased_at' => now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
                'status' => InventoryPurchase::PENDING,
                'expected_at' => $data['expected_at'],
                'expected_quantity' => $quantity,
                'expected_total_amount' => $total,
                'expected_variants' => $variants ?: null,
            ]);
            $after = $purchase->fresh(['supplier', 'product', 'warehouse'])->snapshot();
            FinancialAuditLog::record('inventory_purchase_ordered', 'inventory_purchase', $purchase->id, null, $after);
            InventoryAuditLog::record('purchase_ordered', 'inventory_purchase', $purchase->id, null, $after);

            return $purchase->fresh(['supplier', 'product', 'warehouse']);
        });
    }

    /**
     * Recibir una compra que estaba por recibir (§91, R3): con la cantidad y el costo reales entra al stock, nace el lote
     * FIFO del Módulo 4 y la deuda o el pago, como en una compra normal. Puede llegar a otro almacén (§21).
     *
     * @param array{quantity:int, variants?:mixed, total_amount:float|string, currency:string, rate?:float|string|null,
     *              payment_type:string, due_date?:?string, terms?:?string, financial_account_id?:?int, warehouse_id?:?int,
     *              notes?:?string} $data
     */
    public function receive(InventoryPurchase $purchase, array $data): InventoryPurchase
    {
        return DB::transaction(function () use ($purchase, $data) {
            $purchase = InventoryPurchase::lockForUpdate()->findOrFail($purchase->id);
            if (!$purchase->isPending()) {
                throw ValidationException::withMessages(['purchase' => 'Esta compra ya no está por recibir.']);
            }
            $before = $purchase->fresh(['supplier', 'product', 'warehouse'])->snapshot();
            $supplier = $this->activeSupplier((int) $purchase->supplier_id);
            $product = Product::findOrFail($purchase->product_id);
            $quantity = (int) $data['quantity'];
            $total = round((float) $data['total_amount'], 2);
            $usd = Expense::toUsd($total, $data['currency'], isset($data['rate']) ? (float) $data['rate'] : null);
            $credit = $data['payment_type'] === 'credit';
            $warehouseId = (int) ($data['warehouse_id'] ?? 0) ?: (int) $purchase->warehouse_id;

            $purchase->update([
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'total_amount' => $total,
                'currency' => $data['currency'],
                'rate' => $usd['rate'],
                'total_usd' => $usd['amount_usd'],
                'unit_cost_usd' => round($usd['amount_usd'] / $quantity, 4),
                'payment_type' => $data['payment_type'],
                'due_date' => $credit ? ($data['due_date'] ?? null) : null,
                'terms' => $credit ? ($data['terms'] ?? null) : null,
                'notes' => $data['notes'] ?? $purchase->notes,
                'status' => InventoryPurchase::RECEIVED,
                'received_at' => now(),
            ]);

            $this->stockAndDebt($purchase, $supplier, $product, $data + ['warehouse_id' => $warehouseId], $total, $usd, $credit);
            $after = $purchase->fresh(['supplier', 'product', 'warehouse'])->snapshot();
            FinancialAuditLog::record('inventory_purchase_received', 'inventory_purchase', $purchase->id, $before, $after);
            InventoryAuditLog::record('purchase_received', 'inventory_purchase', $purchase->id, $before, $after);

            return $purchase->fresh(['supplier', 'product', 'warehouse']);
        });
    }

    /** Cancelar una compra que todavía no llegó (no hay nada que deshacer: no movió stock ni dinero). */
    public function cancel(InventoryPurchase $purchase, string $reason): InventoryPurchase
    {
        return DB::transaction(function () use ($purchase, $reason) {
            $purchase = InventoryPurchase::lockForUpdate()->findOrFail($purchase->id);
            if (!$purchase->isPending()) {
                throw ValidationException::withMessages(['purchase' => 'Solo se cancela una compra que todavía está por recibir.']);
            }
            $before = $purchase->fresh(['supplier', 'product', 'warehouse'])->snapshot();
            $purchase->update(['status' => InventoryPurchase::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason]);
            $after = $purchase->fresh(['supplier', 'product', 'warehouse'])->snapshot();
            FinancialAuditLog::record('inventory_purchase_cancelled', 'inventory_purchase', $purchase->id, $before, $after, $reason);
            InventoryAuditLog::record('purchase_cancelled', 'inventory_purchase', $purchase->id, $before, $after, $reason);

            return $purchase->fresh(['supplier', 'product', 'warehouse']);
        });
    }

    private function activeSupplier(int $supplierId): Supplier
    {
        $supplier = Supplier::findOrFail($supplierId);
        if (!$supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => "El proveedor {$supplier->name} está inactivo. Actívalo en Finanzas › Proveedores o elige otro."]);
        }

        return $supplier;
    }

    /** Lo de una compra que llega: las unidades al almacén (con su lote) y la deuda, pagada si es al contado. */
    private function stockAndDebt(InventoryPurchase $purchase, Supplier $supplier, Product $product, array $data, float $total, array $usd, bool $credit): void
    {
        $quantity = (int) $purchase->quantity;
        $this->inventory->addStock(
            $product->id,
            (int) $data['warehouse_id'],
            $quantity,
            auth()->id(),
            trim(($data['notes'] ?? '') ?: "Compra a {$supplier->name}"),
            InventoryPurchase::REFERENCE,
            $purchase->id,
            $data['variants'] ?? null
        );

        $payable = AccountPayable::create([
            'payee_type' => AccountPayable::PAYEE_SUPPLIER,
            'supplier_id' => $supplier->id,
            'source_type' => AccountPayable::SOURCE_PURCHASE,
            'source_id' => $purchase->id,
            'description' => "Compra: {$quantity} × " . ($product->showable_name ?: $product->title),
            'currency' => $data['currency'],
            'amount' => $total,
            'rate' => $usd['rate'],
            'amount_usd' => $usd['amount_usd'],
            'issued_at' => today()->toDateString(),
            'due_date' => $credit ? ($data['due_date'] ?? null) : today()->toDateString(),
            'terms' => $credit ? ($data['terms'] ?? null) : null,
            'created_by' => auth()->id(),
        ]);
        if (!$credit) {
            $this->payables->pay($payable, [
                'amount' => $total,
                'currency' => $data['currency'],
                'rate' => $usd['rate'],
                'financial_account_id' => $data['financial_account_id'] ?? null,
                'notes' => 'Pago al contado de la compra',
            ]);
        }
    }

    /**
     * Corregir el costo de una compra ya registrada (§83: con motivo y en la auditoría). Las unidades no cambian. Cambian
     * el costo unitario de sus lotes (y con eso el de las órdenes que ya los vendieron) y la deuda: al contado, también
     * el pago con que nació pagada. No se acepta si ya se pagó más que el costo nuevo, ni cambiar la moneda con pagos.
     *
     * @param array{total_amount: float|string, currency: string, rate?: float|string|null} $data
     */
    public function correct(InventoryPurchase $purchase, array $data, string $reason): InventoryPurchase
    {
        if ($purchase->isPending()) {
            throw ValidationException::withMessages(['purchase' => 'Esta compra todavía está por recibir: el costo real se pone al recibirla.']);
        }

        return DB::transaction(function () use ($purchase, $data, $reason) {
            $before = $purchase->fresh(['supplier', 'product', 'warehouse'])->snapshot();
            $total = round((float) $data['total_amount'], 2);
            $usd = Expense::toUsd($total, $data['currency'], isset($data['rate']) ? (float) $data['rate'] : null);
            $payable = AccountPayable::where('source_type', AccountPayable::SOURCE_PURCHASE)->where('source_id', $purchase->id)->lockForUpdate()->first();

            if ($payable) {
                $payments = $payable->payments()->whereNull('voided_at')->get();
                $cashPayment = $purchase->payment_type === 'cash' && $payments->count() === 1 ? $payments->first() : null;
                if ($cashPayment) {
                    // Al contado: el pago con que nació pagada pasa a ser por el costo nuevo
                    $cashPayment->update(['amount' => $total, 'currency' => $data['currency'], 'rate' => $usd['rate'], 'amount_usd' => $usd['amount_usd'],
                        'applied_amount' => $data['currency'] === 'VES' ? $total : $usd['amount_usd']]);
                } else {
                    if ($payments->isNotEmpty() && $data['currency'] !== $payable->currency) {
                        throw ValidationException::withMessages(['currency' => 'Esta compra ya tiene pagos: la moneda no se puede cambiar. Corrige solo el monto.']);
                    }
                    if (round($payable->paid_amount, 2) > $total + 0.005) {
                        throw ValidationException::withMessages(['total_amount' => 'Ya se pagó más que ese costo. Anula primero el pago que sobra en Finanzas › Proveedores.']);
                    }
                }
                $payable->update(['currency' => $data['currency'], 'amount' => $total, 'rate' => $usd['rate'], 'amount_usd' => $usd['amount_usd']]);
                $this->payables->recalculate($payable);
            }

            $unit = round($usd['amount_usd'] / max(1, $purchase->quantity), 4);
            $purchase->update(['total_amount' => $total, 'currency' => $data['currency'], 'rate' => $usd['rate'], 'total_usd' => $usd['amount_usd'],
                'unit_cost_usd' => $unit]);
            // Cada lote se guarda por su cuenta: así FinanceTriggers marca las órdenes que vendieron unidades de él
            foreach (InventoryLot::where('purchase_id', $purchase->id)->get() as $lot) {
                $lot->update(['unit_cost_usd' => $unit, 'cost_source' => 'purchase']);
            }
            FinancialAuditLog::record('inventory_purchase_corrected', 'inventory_purchase', $purchase->id, $before,
                $purchase->fresh(['supplier', 'product', 'warehouse'])->snapshot(), $reason);

            return $purchase->fresh(['supplier', 'product', 'warehouse']);
        });
    }
}
