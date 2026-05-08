<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Exception;

class PurchaseOrderController extends Controller
{
    protected InventoryService $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    private function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (!in_array($user?->role?->description, ['Admin', 'Gerente', 'Master'])) {
            abort(403, 'Solo administradores pueden gestionar órdenes de compra.');
        }
    }

    // ── INDEX ─────────────────────────────────────────────────────────────────

    /**
     * List purchase orders with filters
     */
    public function index(Request $request)
    {
        $this->authorizeAdmin();

        $query = PurchaseOrder::with(['supplier', 'warehouse', 'createdBy'])
            ->withCount('items');

        if ($request->has('status')) {
            $statuses = explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $perPage = $request->get('per_page', 20);
        $orders  = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json(['success' => true, 'data' => $orders]);
    }

    // ── SHOW ──────────────────────────────────────────────────────────────────

    /**
     * Full detail of a purchase order, including items
     */
    public function show($id)
    {
        $this->authorizeAdmin();

        $order = PurchaseOrder::with([
            'supplier',
            'warehouse',
            'createdBy',
            'items.product',
        ])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $order]);
    }

    // ── STORE ─────────────────────────────────────────────────────────────────

    /**
     * Create a new purchase order (always starts as draft)
     */
    public function store(Request $request)
    {
        $this->authorizeAdmin();

        $validator = Validator::make($request->all(), [
            'supplier_id'  => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'expected_at'  => 'nullable|date',
            'notes'        => 'nullable|string',
            'items'        => 'required|array|min:1',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.quantity_ordered' => 'required|integer|min:1',
            'items.*.unit_cost_usd'  => 'nullable|numeric|min:0',
            'items.*.unit_cost_ves'  => 'nullable|numeric|min:0',
            'items.*.notes'          => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $order = DB::transaction(function () use ($request) {
            $po = PurchaseOrder::create([
                'supplier_id'      => $request->supplier_id,
                'warehouse_id'     => $request->warehouse_id,
                'created_by'       => Auth::id(),
                'reference_number' => PurchaseOrder::nextReferenceNumber(),
                'status'           => 'draft',
                'expected_at'      => $request->expected_at,
                'notes'            => $request->notes,
            ]);

            foreach ($request->items as $item) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id'        => $item['product_id'],
                    'quantity_ordered'  => $item['quantity_ordered'],
                    'quantity_received' => 0,
                    'unit_cost_usd'     => $item['unit_cost_usd'] ?? 0,
                    'unit_cost_ves'     => $item['unit_cost_ves'] ?? 0,
                    'notes'             => $item['notes'] ?? null,
                ]);
            }

            $po->recalculateTotals();

            return $po->load(['supplier', 'warehouse', 'items.product']);
        });

        return response()->json(['success' => true, 'data' => $order], 201);
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────

    /**
     * Update a draft purchase order (header + replace items)
     */
    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();

        $order = PurchaseOrder::findOrFail($id);

        if ($order->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden editar órdenes en estado Borrador.',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'supplier_id'  => 'sometimes|exists:suppliers,id',
            'warehouse_id' => 'sometimes|exists:warehouses,id',
            'expected_at'  => 'nullable|date',
            'notes'        => 'nullable|string',
            'items'        => 'sometimes|array|min:1',
            'items.*.product_id'       => 'required_with:items|exists:products,id',
            'items.*.quantity_ordered' => 'required_with:items|integer|min:1',
            'items.*.unit_cost_usd'    => 'nullable|numeric|min:0',
            'items.*.unit_cost_ves'    => 'nullable|numeric|min:0',
            'items.*.notes'            => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $order) {
            $order->update($request->only(['supplier_id', 'warehouse_id', 'expected_at', 'notes']));

            if ($request->has('items')) {
                $order->items()->delete();
                foreach ($request->items as $item) {
                    PurchaseOrderItem::create([
                        'purchase_order_id' => $order->id,
                        'product_id'        => $item['product_id'],
                        'quantity_ordered'  => $item['quantity_ordered'],
                        'quantity_received' => 0,
                        'unit_cost_usd'     => $item['unit_cost_usd'] ?? 0,
                        'unit_cost_ves'     => $item['unit_cost_ves'] ?? 0,
                        'notes'             => $item['notes'] ?? null,
                    ]);
                }
                $order->recalculateTotals();
            }
        });

        return response()->json([
            'success' => true,
            'data'    => $order->load(['supplier', 'warehouse', 'items.product']),
        ]);
    }

    // ── UPDATE STATUS ─────────────────────────────────────────────────────────

    /**
     * Advance the status of a PO (draft→sent, sent→confirmed)
     */
    public function updateStatus(Request $request, $id)
    {
        $this->authorizeAdmin();

        $order = PurchaseOrder::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:draft,sent,confirmed,partial,received,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $newStatus  = $request->status;
        $current    = $order->status;

        // Guard against invalid transitions
        $allowed = [
            'draft'     => ['sent', 'cancelled'],
            'sent'      => ['confirmed', 'cancelled', 'draft'],
            'confirmed' => ['cancelled'],
            'partial'   => ['cancelled'],
            'received'  => [], // terminal
            'cancelled' => [], // terminal
        ];

        if (!in_array($newStatus, $allowed[$current] ?? [])) {
            return response()->json([
                'success' => false,
                'message' => "Transición inválida: {$current} → {$newStatus}",
            ], 400);
        }

        $order->status = $newStatus;
        $order->save();

        return response()->json([
            'success' => true,
            'message' => "Orden actualizada a: {$order->status_label}",
            'data'    => $order->load(['supplier', 'warehouse']),
        ]);
    }

    // ── RECEIVE ───────────────────────────────────────────────────────────────

    /**
     * Register physical receipt of goods — updates stock automatically
     *
     * Payload: { items: [{ purchase_order_item_id, quantity_received }] }
     */
    public function receive(Request $request, $id)
    {
        $this->authorizeAdmin();

        $order = PurchaseOrder::with('items.product')->findOrFail($id);

        if (!in_array($order->status, ['confirmed', 'partial', 'sent'])) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se puede recepcionar una orden Enviada, Confirmada o Parcialmente recibida.',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'items'                            => 'required|array|min:1',
            'items.*.purchase_order_item_id'   => 'required|exists:purchase_order_items,id',
            'items.*.quantity_received'        => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $order) {
            foreach ($request->items as $recv) {
                $item = PurchaseOrderItem::where('purchase_order_id', $order->id)
                    ->findOrFail($recv['purchase_order_item_id']);

                $qtyToAdd = (int) $recv['quantity_received'];

                if ($qtyToAdd <= 0) continue;

                // Don't allow receiving more than ordered
                $maxAdditional = $item->quantity_ordered - $item->quantity_received;
                $qtyToAdd = min($qtyToAdd, $maxAdditional);

                if ($qtyToAdd > 0) {
                    // Add to warehouse stock
                    $this->inventoryService->addStock(
                        productId:    $item->product_id,
                        warehouseId:  $order->warehouse_id,
                        quantity:     $qtyToAdd,
                        userId:       Auth::id(),
                        notes:        "Recepción OC {$order->reference_number}",
                        referenceType: 'purchase_order',
                        referenceId:  $order->id,
                    );

                    $item->quantity_received += $qtyToAdd;
                    $item->save();
                }
            }

            // Determine new order status
            $order->refresh();
            $order->load('items');

            if ($order->isFullyReceived()) {
                $order->status      = 'received';
                $order->received_at = now();
            } else {
                $order->status = 'partial';
            }

            $order->save();
        });

        return response()->json([
            'success' => true,
            'message' => $order->status === 'received'
                ? '✅ Recepción completa. Stock actualizado en bodega.'
                : '📦 Recepción parcial registrada. Puedes completar el resto más tarde.',
            'data' => $order->load(['supplier', 'warehouse', 'items.product']),
        ]);
    }

    // ── CANCEL ────────────────────────────────────────────────────────────────

    /**
     * Cancel a purchase order
     */
    public function cancel($id)
    {
        $this->authorizeAdmin();

        $order = PurchaseOrder::findOrFail($id);

        if (in_array($order->status, ['received', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede cancelar una orden ya cerrada.',
            ], 400);
        }

        $order->status = 'cancelled';
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Orden de compra cancelada.',
            'data'    => $order,
        ]);
    }
}
