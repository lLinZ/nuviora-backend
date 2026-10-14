<?php

namespace App\Services\Finance;

use App\Models\AccountPayable;
use App\Models\Expense;
use App\Models\FinancialAuditLog;
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
}
