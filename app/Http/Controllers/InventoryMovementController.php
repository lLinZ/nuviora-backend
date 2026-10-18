<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

class InventoryMovementController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * Helper to validate warehouse ownership
     * @param int $warehouseId
     * @return bool|string True if allowed, error message string if denied
     */
    private function validateOwnership($warehouseId)
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (!$user) return 'Usuario no autenticado';

        // Superusers can access anything
        if (in_array($user->role?->description, ['Admin', 'Gerente', 'Master'])) {
            return true;
        }

        // Agencies can only access their own warehouses
        if ($user->role?->description === 'Agencia') {
            $warehouse = \App\Models\Warehouse::find($warehouseId);
            if (!$warehouse) return 'Almacén no encontrado';
            
            if ($warehouse->user_id !== $user->id) {
                return '⛔ ACCESO DENEGADO: No tienes permiso para modificar este almacén.';
            }
            return true;
        }

        return '⛔ Rol no autorizado para gestión de inventario.';
    }

    /**
     * Display a listing of inventory movements
     */
    public function index(Request $request)
    {
        $query = InventoryMovement::with(['product', 'fromWarehouse', 'toWarehouse', 'user']);

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by warehouse (from or to)
        if ($request->has('warehouse_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('from_warehouse_id', $request->warehouse_id)
                  ->orWhere('to_warehouse_id', $request->warehouse_id);
            });
        }

        // 🔒 RESTRICT AGENCY TO OWN WAREHOUSE MOVEMENTS
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user->role?->description === 'Agencia') {
            $query->where(function($q) use ($user) {
                $q->whereHas('fromWarehouse', function($qw) use ($user) {
                    $qw->where('user_id', $user->id);
                })->orWhereHas('toWarehouse', function($qw) use ($user) {
                    $qw->where('user_id', $user->id);
                });
            });
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Filter by status (Fase 4)
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by movement_type
        if ($request->has('movement_type')) {
            $query->where('movement_type', $request->movement_type);
        }

        // Order by most recent
        $query->orderBy('created_at', 'desc');

        // Pagination
        $perPage = $request->get('per_page', 15);
        $movements = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $movements,
        ]);
    }

    /**
     * Display a specific movement
     */
    public function show($id)
    {
        $movement = InventoryMovement::with(['product', 'fromWarehouse', 'toWarehouse', 'user'])
            ->findOrFail($id);

        // 🔒 CHECK ACCESS
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user->role?->description === 'Agencia') {
            $canView = false;
            if ($movement->fromWarehouse && $movement->fromWarehouse->user_id === $user->id) $canView = true;
            if ($movement->toWarehouse && $movement->toWarehouse->user_id === $user->id) $canView = true;
            
            if (!$canView) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $movement,
        ]);
    }

    /**
     * Transfer stock between warehouses
     */
    public function transfer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id' => 'required|exists:warehouses,id|different:from_warehouse_id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // 🔒 SECURITY CHECK
        $checkFrom = $this->validateOwnership($request->from_warehouse_id);
        if ($checkFrom !== true) return response()->json(['success' => false, 'message' => $checkFrom], 403);

        // Si es Agencia, NO permitimos mover stock HACIA almacenes ajenos (a menos que sea devolución al central, lógica pendiente)
        // Por ahora lo dejamos simple: validar origen.

        // Módulo 5 (§15, R2): el traslado ya no es inmediato. Sale del origen y queda "En tránsito" hasta que se marca
        // "Recibido"; recién ahí entra al destino.
        try {
            $transfers = app(\App\Services\Inventory\InventoryTransfers::class);
            $transfer = $transfers->create(
                (int) $request->from_warehouse_id,
                (int) $request->to_warehouse_id,
                $transfers->linesFor((int) $request->product_id, (int) $request->quantity, $this->variantsFrom($request)),
                $request->notes
            );

            return response()->json([
                'success' => true,
                'message' => "Traslado en camino: entra a {$transfer->toWarehouse?->name} cuando lo marques \"Recibido\" (Inventario › Traslados).",
                'transfer' => $transfer->toPayload(),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => collect($e->errors())->flatten()->first()], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Add stock to warehouse (incoming)
     */
    public function stockIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'to_warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'notes' => 'nullable|string',
            'sizes' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // 🔒 SECURITY CHECK
        $checkTo = $this->validateOwnership($request->to_warehouse_id);
        if ($checkTo !== true) return response()->json(['success' => false, 'message' => $checkTo], 403);

        // Módulo 4 de Fran (§9, §16, R6): el ingreso de una compra trae su costo, el proveedor y cómo se paga
        if ($request->filled('purchase')) {
            return $this->stockInPurchase($request);
        }

        try {
            $movement = $this->inventoryService->addStock(
                $request->product_id,
                $request->to_warehouse_id,
                $request->quantity,
                auth()->id(),
                $request->notes,
                $request->reference_type,
                $request->reference_id,
                $this->variantsFrom($request)
            );

            return response()->json([
                'success' => true,
                'message' => 'Stock added successfully',
                'data' => $movement->load(['product', 'toWarehouse']),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Remove stock from warehouse (outgoing)
     */
    public function stockOut(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'notes' => 'nullable|string',
            'sizes' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // 🔒 SECURITY CHECK
        $checkFrom = $this->validateOwnership($request->from_warehouse_id);
        if ($checkFrom !== true) return response()->json(['success' => false, 'message' => $checkFrom], 403);

        try {
            $movement = $this->inventoryService->removeStock(
                $request->product_id,
                $request->from_warehouse_id,
                $request->quantity,
                auth()->id(),
                $request->notes,
                $request->reference_type,
                $request->reference_id,
                $this->variantsFrom($request)
            );

            return response()->json([
                'success' => true,
                'message' => 'Stock removed successfully',
                'data' => $movement->load(['product', 'fromWarehouse']),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Adjust stock in warehouse
     */
    public function adjust(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'new_quantity' => 'required|integer|min:0',
            'notes' => 'nullable|string|required_if:reason,other',
            'sizes' => 'nullable|array',
            // Módulo 5, §94: el tipo del ajuste. Sin tipo es un error de conteo, que es lo que es un ajuste (Fran, 14/10)
            'reason' => 'nullable|in:' . implode(',', array_keys(InventoryMovement::COUNT_REASONS)),
        ], ['notes.required_if' => 'Con "Otro", explica el motivo del ajuste.']);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // 🔒 SECURITY CHECK
        $check = $this->validateOwnership($request->warehouse_id);
        if ($check !== true) return response()->json(['success' => false, 'message' => $check], 403);

        try {
            $reason = $request->input('reason') ?: 'count_error';
            $movement = $this->inventoryService->adjustStock(
                $request->product_id,
                $request->warehouse_id,
                $request->new_quantity,
                auth()->id(),
                $request->notes,
                $this->variantsFrom($request),
                $reason
            );
            // §95: quién, cuándo, antes, después y motivo
            \App\Models\InventoryAuditLog::record('stock_adjusted', 'inventory_movement', $movement->id,
                ['quantity' => $movement->details['old'] ?? null],
                ['quantity' => $movement->details['new'] ?? null, 'variants' => $movement->details['variants'] ?? null, 'reason' => $reason],
                trim(InventoryMovement::COUNT_REASONS[$reason] . ($request->notes ? " · {$request->notes}" : '')));

            return response()->json([
                'success' => true,
                'message' => 'Stock adjusted successfully',
                'data' => $movement->load(['product', 'toWarehouse']),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Salida por muestra, regalo o uso interno (Módulo 5 de Fran, §93, §94, R4): sale de los lotes (FIFO) y su costo cuenta
     * como gasto, igual que la merma. No es una venta. Solo el Admin: tiene efecto en la rentabilidad (§81 del Módulo 4).
     */
    public function writeOff(Request $request)
    {
        if (\Illuminate\Support\Facades\Auth::user()?->role?->description !== 'Admin') {
            return response()->json(['success' => false, 'message' => 'Solo el administrador registra muestras, regalos o uso interno.'], 403);
        }
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'reason' => 'required|in:' . implode(',', array_keys(InventoryMovement::WRITE_OFF_REASONS)),
            'notes' => 'required|string|min:3|max:500',
            'variants' => 'nullable|array',
            'sizes' => 'nullable|array',
        ], [
            'reason.required' => 'Elige si es muestra, regalo o uso interno.',
            'notes.required' => 'Escribe el motivo: para quién o para qué.',
        ]);

        try {
            $movement = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $request) {
                $inventory = \App\Models\Inventory::where('warehouse_id', $data['warehouse_id'])->where('product_id', $data['product_id'])->lockForUpdate()->first();
                $before = (int) ($inventory->quantity ?? 0);
                // Lo que está en revisión no sale por aquí: tiene su propio camino ("Dar de baja", Módulo 4, R8)
                if ($before - (int) ($inventory->defective_stock ?? 0) < (int) $data['quantity']) {
                    throw new Exception('No hay tantas unidades vendibles en ese almacén (lo que está en revisión no cuenta).');
                }
                $label = InventoryMovement::WRITE_OFF_REASONS[$data['reason']];
                $movement = $this->inventoryService->removeStock(
                    (int) $data['product_id'],
                    (int) $data['warehouse_id'],
                    (int) $data['quantity'],
                    auth()->id(),
                    "{$label}: {$data['notes']}",
                    InventoryMovement::WRITE_OFF_REFERENCE,
                    null,
                    $this->variantsFrom($request),
                    ['reason' => $data['reason'], 'details' => ['old' => $before, 'new' => $before - (int) $data['quantity']]]
                );
                \App\Models\InventoryAuditLog::record('stock_written_off', 'inventory_movement', $movement->id,
                    ['quantity' => $before], ['quantity' => $before - (int) $data['quantity'], 'reason' => $data['reason']], "{$label}: {$data['notes']}");

                return $movement;
            });
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Salida registrada. Su costo cuenta como gasto, igual que la merma.',
            'data' => $movement->load(['product', 'fromWarehouse']),
        ], 201);
    }

    /** Desglose por variante (tarea 4): `variants` ({variant_id: cantidad}) o el viejo `sizes` (por nombre de talla). */
    private function variantsFrom(Request $request)
    {
        return $request->input('variants') ?: $request->input('sizes');
    }

    /**
     * Ingreso con compra (Módulo 4 de Fran, §9, §16, §17, R6): solo el Admin (§81). Entran las unidades, queda la compra
     * con su costo unitario (§9) y nace la deuda con el proveedor: abierta si es a crédito, pagada si es al contado.
     */
    private function stockInPurchase(Request $request)
    {
        if (\Illuminate\Support\Facades\Auth::user()?->role?->description !== 'Admin') {
            return response()->json(['success' => false, 'message' => 'Solo el administrador registra el costo de una compra.'], 403);
        }
        $purchase = Validator::make((array) $request->input('purchase'), [
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'total_amount' => 'required|numeric|min:0.01|max:9999999999',
            'currency' => 'required|in:USD,USDT,VES',
            'rate' => 'nullable|required_if:currency,VES|numeric|min:0.0001',
            'payment_type' => 'required|in:cash,credit',
            'due_date' => 'nullable|required_if:payment_type,credit|date|after_or_equal:today',
            'terms' => 'nullable|string|max:500',
            'financial_account_id' => 'nullable|integer|exists:financial_accounts,id',
        ], [
            'supplier_id.required' => 'Elige el proveedor de la compra.',
            'total_amount.required' => 'Escribe cuánto costó la compra en total.',
            'total_amount.min' => 'El costo total tiene que ser mayor que 0.',
            'rate.required_if' => 'En bolívares, escribe la tasa (bolívares por dólar) de esta compra.',
            'payment_type.required' => 'Elige si la compra es al contado o a crédito.',
            'due_date.required_if' => 'A crédito, elige la fecha de vencimiento.',
            'due_date.after_or_equal' => 'El vencimiento no puede ser anterior a hoy.',
        ])->validate();

        try {
            $created = app(\App\Services\Finance\InventoryPurchases::class)->create($purchase + [
                'product_id' => (int) $request->product_id,
                'warehouse_id' => (int) $request->to_warehouse_id,
                'quantity' => (int) $request->quantity,
                'variants' => $this->variantsFrom($request),
                'notes' => $request->notes,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            // Lo que rechaza el inventario (por ejemplo, tallas que suman más que la cantidad): no queda nada registrado
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
        $unit = number_format($created->unit_cost_usd, 2, ',', '.');

        return response()->json([
            'success' => true,
            'message' => "Compra registrada: {$created->quantity} unidades a \${$unit} cada una"
                . ($created->payment_type === 'credit' ? '. La deuda queda en Finanzas › Proveedores.' : ', pagada al contado.'),
            'purchase' => $created->toPayload(),
        ], 201);
    }

    /**
     * Confirm a pending transfer (Admin Only)
     */
    public function confirm($id)
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (!in_array($user->role?->description, ['Admin', 'Gerente', 'Master'])) {
            return response()->json(['success' => false, 'message' => 'Solo administradores pueden confirmar transferencias.'], 403);
        }

        try {
            $movement = $this->inventoryService->confirmTransfer($id, auth()->id());
            return response()->json([
                'success' => true,
                'message' => 'Transfer confirmed and stock added to destination.',
                'data' => $movement->load(['product', 'fromWarehouse', 'toWarehouse']),
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Reject a pending transfer (Admin Only)
     */
    public function reject($id)
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (!in_array($user->role?->description, ['Admin', 'Gerente', 'Master'])) {
            return response()->json(['success' => false, 'message' => 'Solo administradores pueden rechazar transferencias.'], 403);
        }

        try {
            $movement = $this->inventoryService->rejectTransfer($id, auth()->id());
            return response()->json([
                'success' => true,
                'message' => 'Transfer rejected and stock returned to source.',
                'data' => $movement->load(['product', 'fromWarehouse', 'toWarehouse']),
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
