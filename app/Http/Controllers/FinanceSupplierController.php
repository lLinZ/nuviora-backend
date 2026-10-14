<?php

namespace App\Http\Controllers;

use App\Models\AccountPayable;
use App\Models\FinancialAuditLog;
use App\Models\InventoryPurchase;
use App\Models\PayablePayment;
use App\Models\Supplier;
use App\Services\Finance\InventoryPurchases;
use App\Services\Finance\Payables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Finanzas › Proveedores (Módulo 4 de Fran, §10, §16, §17, §44). Solo el Admin (§81, por la ruta).
 * - Proveedores: nombre y activo/inactivo (§10), con lo comprado, lo que se les debe y lo vencido.
 * - Deudas (§44): beneficiario, monto, vencimiento, pagado, pendiente y vencido, con sus pagos.
 * - Pagos (§17): varios por deuda; cada uno baja el saldo y no cambia el costo del inventario. Se anulan con motivo (§83).
 */
class FinanceSupplierController extends Controller
{
    private const MESSAGES = [
        'name.required' => 'Escribe el nombre del proveedor.',
        'name.unique' => 'Ya hay un proveedor con ese nombre.',
        'amount.required' => 'Escribe el monto del pago.',
        'amount.min' => 'El monto tiene que ser mayor que 0.',
        'rate.required_if' => 'En bolívares, escribe la tasa (bolívares por dólar) de este pago.',
        'paid_at.before_or_equal' => 'La fecha del pago no puede ser futura.',
        'reason.required' => 'Escribe por qué se anula.',
        'receipt.mimes' => 'El comprobante tiene que ser una imagen o un PDF.',
        'receipt.max' => 'El comprobante no puede pasar de 10 MB.',
    ];

    /** Los proveedores con lo comprado, lo que se les debe y lo vencido, y los totales de arriba. */
    public function index(): JsonResponse
    {
        $payables = AccountPayable::where('payee_type', AccountPayable::PAYEE_SUPPLIER)->get();
        $bySupplier = $payables->groupBy('supplier_id');
        $purchases = InventoryPurchase::groupBy('supplier_id')
            ->selectRaw('supplier_id, COUNT(*) AS n, SUM(total_usd) AS usd, MAX(purchased_at) AS last_at')->get()->keyBy('supplier_id');
        $soon = today()->addDays(7);

        $rows = Supplier::orderByDesc('is_active')->orderBy('name')->get()->map(function (Supplier $s) use ($bySupplier, $purchases) {
            $open = ($bySupplier[$s->id] ?? collect())->filter(fn (AccountPayable $p) => $p->balance() > 0);

            return [
                'id' => $s->id,
                'name' => $s->name,
                'contact_name' => $s->contact_name,
                'phone' => $s->phone,
                'notes' => $s->notes,
                'is_active' => (bool) $s->is_active,
                'purchases' => (int) ($purchases[$s->id]->n ?? 0),
                'purchased_usd' => round((float) ($purchases[$s->id]->usd ?? 0), 2),
                'last_purchase' => isset($purchases[$s->id]) ? substr((string) $purchases[$s->id]->last_at, 0, 10) : null,
                'debt_usd' => round($open->sum(fn ($p) => $p->balanceUsd()), 2),
                'overdue_usd' => round($open->filter(fn ($p) => $p->status() === 'overdue')->sum(fn ($p) => $p->balanceUsd()), 2),
                'open_debts' => $open->count(),
                'next_due' => $open->pluck('due_date')->filter()->min()?->format('Y-m-d'),
            ];
        })->values();

        $open = $payables->filter(fn (AccountPayable $p) => $p->balance() > 0);

        return response()->json([
            'suppliers' => $rows,
            'totals' => [
                'debt_usd' => round($open->sum(fn ($p) => $p->balanceUsd()), 2),
                'overdue_usd' => round($open->filter(fn ($p) => $p->status() === 'overdue')->sum(fn ($p) => $p->balanceUsd()), 2),
                'due_soon_usd' => round($open->filter(fn ($p) => $p->status() === 'open' && $p->due_date && $p->due_date->lte($soon))
                    ->sum(fn ($p) => $p->balanceUsd()), 2),
                'open_debts' => $open->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->supplierRules(), self::MESSAGES);
        $supplier = Supplier::create($this->supplierAttributes($data) + ['currency' => 'USD', 'default_lead_time_days' => 7, 'is_active' => true]);
        FinancialAuditLog::record('supplier_created', 'supplier', $supplier->id, null, $this->supplierSnapshot($supplier));

        return response()->json(['message' => "Proveedor \"{$supplier->name}\" creado.",
            'supplier' => ['id' => $supplier->id, 'name' => $supplier->name, 'is_active' => true]], 201);
    }

    /** Nombre, contacto, notas y activo/inactivo (§10). Un proveedor inactivo no aparece para compras nuevas. */
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate($this->supplierRules($supplier->id) + ['is_active' => 'required|boolean'], self::MESSAGES);
        $before = $this->supplierSnapshot($supplier);
        $supplier->update($this->supplierAttributes($data) + ['is_active' => (bool) $data['is_active']]);
        $after = $this->supplierSnapshot($supplier->fresh());
        if ($after !== $before) {
            FinancialAuditLog::record('supplier_updated', 'supplier', $supplier->id, $before, $after);
        }

        return response()->json(['message' => 'Proveedor actualizado.']);
    }

    /** Un proveedor: sus deudas con sus pagos y sus compras. */
    public function show(Supplier $supplier): JsonResponse
    {
        $payables = AccountPayable::with(['supplier:id,name', 'payments.account:id,name', 'payments.creator:id,names', 'payments.voider:id,names'])
            ->where('payee_type', AccountPayable::PAYEE_SUPPLIER)->where('supplier_id', $supplier->id)
            ->orderByDesc('issued_at')->orderByDesc('id')->get();
        $purchases = InventoryPurchase::with(['supplier:id,name', 'product:id,title', 'warehouse:id,name', 'creator:id,names'])
            ->where('supplier_id', $supplier->id)->orderByDesc('purchased_at')->orderByDesc('id')->limit(200)->get();

        return response()->json([
            'supplier' => ['id' => $supplier->id, 'name' => $supplier->name, 'is_active' => (bool) $supplier->is_active],
            'payables' => $payables->map(fn (AccountPayable $p) => $p->toPayload(true))->values(),
            'purchases' => $purchases->map->toPayload()->values(),
        ]);
    }

    /** Las deudas de todos los proveedores, por estado (§44): abiertas (con lo vencido), vencidas, pagadas o todas. */
    public function payables(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'overdue', 'paid', 'all'])]]);
        $status = $data['status'] ?? 'open';
        $rows = AccountPayable::with(['supplier:id,name', 'payments.account:id,name', 'payments.creator:id,names', 'payments.voider:id,names'])
            ->where('payee_type', AccountPayable::PAYEE_SUPPLIER)
            ->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('id')->get()
            ->filter(fn (AccountPayable $p) => match ($status) {
                'open' => $p->status() !== 'paid',
                'overdue' => $p->status() === 'overdue',
                'paid' => $p->status() === 'paid',
                default => true,
            });

        return response()->json(['payables' => $rows->map(fn (AccountPayable $p) => $p->toPayload(true))->values()]);
    }

    /** §17: un pago (parcial o total). Puede traer comprobante. */
    public function pay(Request $request, AccountPayable $payable, Payables $payables): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:9999999999',
            'currency' => 'required|in:USD,USDT,VES',
            'rate' => 'nullable|required_if:currency,VES|numeric|min:0.0001',
            'paid_at' => 'required|date|before_or_equal:today',
            'financial_account_id' => 'nullable|integer|exists:financial_accounts,id',
            'notes' => 'nullable|string|max:500',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ], self::MESSAGES);
        $payment = $payables->pay($payable, $data, $request->file('receipt'));
        $fresh = $payable->fresh();
        $left = $fresh->balance();
        $unit = $fresh->currency === 'VES' ? 'Bs ' : '$';

        return response()->json([
            'message' => $left > 0 ? "Pago registrado. Queda por pagar {$unit}" . number_format($left, 2, ',', '.') . '.' : 'Pago registrado. La deuda quedó saldada.',
            'payment' => $payment->fresh(['account'])->toPayload(),
            'payable' => $fresh->load('payments')->toPayload(true),
        ], 201);
    }

    /** §83: el pago no se borra. Se anula con motivo y la deuda vuelve a subir. */
    public function voidPayment(Request $request, PayablePayment $payment, Payables $payables): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500'], self::MESSAGES);
        $payables->void($payment, $data['reason']);

        return response()->json(['message' => 'Pago anulado: la deuda vuelve a tener ese saldo. Queda en el historial.']);
    }

    public function paymentReceipt(PayablePayment $payment)
    {
        abort_unless($payment->receipt_path && Storage::disk('local')->exists($payment->receipt_path), 404, 'El comprobante ya no está.');

        return Storage::disk('local')->download($payment->receipt_path, "pago-{$payment->id}." . pathinfo($payment->receipt_path, PATHINFO_EXTENSION));
    }

    /** Corregir el costo de una compra (entrega 2e, §83): cambia sus lotes y su deuda, con motivo. */
    public function correctPurchase(Request $request, InventoryPurchase $purchase, InventoryPurchases $purchases): JsonResponse
    {
        $data = $request->validate([
            'total_amount' => 'required|numeric|min:0.01|max:9999999999',
            'currency' => 'required|in:USD,USDT,VES',
            'rate' => 'nullable|required_if:currency,VES|numeric|min:0.0001',
            'reason' => 'required|string|max:500',
        ], self::MESSAGES + ['total_amount.required' => 'Escribe el costo total correcto de la compra.', 'reason.required' => 'Escribe el motivo de la corrección.']);
        $fixed = $purchases->correct($purchase, $data, $data['reason']);
        $unit = number_format($fixed->unit_cost_usd, 2, ',', '.');

        return response()->json(['message' => "Compra corregida: \${$unit} por unidad. Las órdenes que vendieron esas unidades se recalculan en un minuto.",
            'purchase' => $fixed->toPayload()]);
    }

    /** El comprobante de una compra (§9, opcional): se sube después del ingreso. El anterior no se borra (§83). */
    public function uploadPurchaseReceipt(Request $request, InventoryPurchase $purchase): JsonResponse
    {
        $request->validate(['receipt' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240'],
            self::MESSAGES + ['receipt.required' => 'Elige el archivo del comprobante.']);
        $had = $purchase->receipt_path !== null;
        $purchase->update(['receipt_path' => $request->file('receipt')->store('purchases', 'local')]);
        FinancialAuditLog::record('inventory_purchase_receipt', 'inventory_purchase', $purchase->id, ['has_receipt' => $had], ['has_receipt' => true]);

        return response()->json(['message' => 'Comprobante guardado.']);
    }

    public function purchaseReceipt(InventoryPurchase $purchase)
    {
        abort_unless($purchase->receipt_path && Storage::disk('local')->exists($purchase->receipt_path), 404, 'El comprobante ya no está.');

        return Storage::disk('local')->download($purchase->receipt_path, "compra-{$purchase->id}." . pathinfo($purchase->receipt_path, PATHINFO_EXTENSION));
    }

    private function supplierRules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')->ignore($ignoreId)],
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    private function supplierAttributes(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'contact_name' => $data['contact_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function supplierSnapshot(Supplier $s): array
    {
        return ['name' => $s->name, 'contact_name' => $s->contact_name, 'phone' => $s->phone, 'notes' => $s->notes, 'is_active' => (bool) $s->is_active];
    }
}
