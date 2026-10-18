<?php

namespace App\Http\Controllers;

use App\Models\InventoryPurchase;
use App\Services\Finance\InventoryPurchases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Compras por recibir (Módulo 5 de Fran, §16, §90, §91, R3). Solo el Admin, como las compras del Módulo 4 (§81). Se
 * registran al hacerlas, con la fecha en que deberían llegar, y se reciben con la cantidad y el costo reales.
 */
class FinancePurchaseController extends Controller
{
    public function __construct(private InventoryPurchases $purchases)
    {
    }

    /** Las compras por recibir, de la que llega antes a la que llega después. Con ?status=all, también las demás. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in([...array_keys(InventoryPurchase::STATUSES), 'all'])]]);
        $status = $data['status'] ?? InventoryPurchase::PENDING;
        $rows = InventoryPurchase::with(['supplier:id,name', 'product:id,title', 'product.variants:id,product_id,title,is_active', 'warehouse:id,name', 'creator:id,names'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw('expected_at IS NULL')->orderBy('expected_at')->orderByDesc('id')->limit(300)->get();

        // Con las tallas del producto, para repartir lo recibido (tarea 4)
        return response()->json(['purchases' => $rows->map(fn (InventoryPurchase $p) => $p->toPayload() + [
            'product_variants' => $p->product?->variants->map(fn ($v) => ['id' => $v->id, 'title' => $v->title, 'is_active' => (bool) $v->is_active])->values() ?? [],
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'variants' => 'nullable|array',
            'total_amount' => 'required|numeric|min:0.01|max:9999999999',
            'currency' => 'required|in:USD,USDT,VES',
            'rate' => 'nullable|required_if:currency,VES|numeric|min:0.0001',
            'expected_at' => 'required|date|after_or_equal:today',
            'notes' => 'nullable|string|max:500',
        ], $this->messages() + [
            'expected_at.required' => 'Elige la fecha en que debería llegar.',
            'expected_at.after_or_equal' => 'La fecha en que llega no puede ser anterior a hoy.',
            'total_amount.required' => 'Escribe cuánto debería costar la compra en total.',
        ]);

        $purchase = $this->guard(fn () => $this->purchases->createPending($data));
        if ($purchase instanceof JsonResponse) {
            return $purchase;
        }

        return response()->json([
            'message' => "Compra por recibir: {$purchase->quantity} unidades, llega el {$purchase->expected_at?->format('d/m')}. No suma stock hasta que la recibas.",
            'purchase' => $purchase->toPayload(),
        ], 201);
    }

    public function receive(Request $request, InventoryPurchase $purchase): JsonResponse
    {
        $data = $request->validate([
            'quantity' => 'required|integer|min:1',
            'variants' => 'nullable|array',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'total_amount' => 'required|numeric|min:0.01|max:9999999999',
            'currency' => 'required|in:USD,USDT,VES',
            'rate' => 'nullable|required_if:currency,VES|numeric|min:0.0001',
            'payment_type' => 'required|in:cash,credit',
            'due_date' => 'nullable|required_if:payment_type,credit|date|after_or_equal:today',
            'terms' => 'nullable|string|max:500',
            'financial_account_id' => 'nullable|integer|exists:financial_accounts,id',
            'notes' => 'nullable|string|max:500',
        ], $this->messages() + [
            'total_amount.required' => 'Escribe cuánto costó de verdad la compra.',
            'payment_type.required' => 'Elige si la compra es al contado o a crédito.',
            'due_date.required_if' => 'A crédito, elige la fecha de vencimiento.',
            'due_date.after_or_equal' => 'El vencimiento no puede ser anterior a hoy.',
        ]);

        $received = $this->guard(fn () => $this->purchases->receive($purchase, $data));
        if ($received instanceof JsonResponse) {
            return $received;
        }
        $unit = number_format($received->unit_cost_usd, 2, ',', '.');

        return response()->json([
            'message' => "Compra recibida: {$received->quantity} unidades a \${$unit} cada una"
                . ($received->payment_type === 'credit' ? '. La deuda queda en Finanzas › Proveedores.' : ', pagada al contado.'),
            'purchase' => $received->toPayload(),
        ]);
    }

    public function cancel(Request $request, InventoryPurchase $purchase): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:500'], ['reason.required' => 'Escribe por qué se cancela.']);
        $cancelled = $this->guard(fn () => $this->purchases->cancel($purchase, $data['reason']));
        if ($cancelled instanceof JsonResponse) {
            return $cancelled;
        }

        return response()->json(['message' => 'Compra cancelada.', 'purchase' => $cancelled->toPayload()]);
    }

    /** Lo que rechaza el inventario (por ejemplo, tallas que suman más que la cantidad) vuelve como mensaje: no queda nada. */
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    private function messages(): array
    {
        return [
            'supplier_id.required' => 'Elige el proveedor de la compra.',
            'total_amount.min' => 'El costo total tiene que ser mayor que 0.',
            'rate.required_if' => 'En bolívares, escribe la tasa (bolívares por dólar) de esta compra.',
        ];
    }
}
