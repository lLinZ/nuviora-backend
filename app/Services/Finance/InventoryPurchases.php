<?php

namespace App\Services\Finance;

use App\Models\AccountPayable;
use App\Models\Expense;
use App\Models\FinancialAuditLog;
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
 */
class InventoryPurchases
{
    public function __construct(private InventoryService $inventory, private Payables $payables)
    {
    }

    /**
     * @param array{product_id:int, warehouse_id:int, quantity:int, variants?:mixed, notes?:?string, supplier_id:int,
     *              total_amount:float|string, currency:string, rate?:float|string|null, payment_type:string, due_date?:?string,
     *              terms?:?string, company_account_id?:?int} $data
     */
    public function create(array $data): InventoryPurchase
    {
        $supplier = Supplier::findOrFail($data['supplier_id']);
        if (!$supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => "El proveedor {$supplier->name} está inactivo. Actívalo en Finanzas › Proveedores o elige otro."]);
        }
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
            ]);

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
                    'company_account_id' => $data['company_account_id'] ?? null,
                    'notes' => 'Pago al contado de la compra',
                ]);
            }

            FinancialAuditLog::record('inventory_purchase_created', 'inventory_purchase', $purchase->id, null, $purchase->fresh()->snapshot());

            return $purchase->fresh(['supplier', 'product', 'warehouse']);
        });
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
