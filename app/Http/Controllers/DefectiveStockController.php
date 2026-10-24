<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\InventoryVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Inventory\DefectiveReview;
use App\Services\Inventory\VariantStock;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Piezas en revisión (Fran, 2026-10-02; Módulo 4, R3 y R8). La pieza que vuelve de un cambio queda en el almacén de
 * la agencia como defectuosa: existe, pero no se cuenta como disponible. Lo mismo la mercancía dañada o perdida (R8),
 * que el Admin pasa a revisión ("Pasar a revisión"). Después de revisarla, el administrador decide:
 *  - "Vuelve a la venta" (restock): estaba bien; deja de ser defectuosa y se puede vender, con su lote y su costo.
 *  - "Dar de baja" (discard): está dañada o no está; sale del inventario. Su costo es merma en la rentabilidad.
 * Cada decisión queda como movimiento del producto, para que el inventario siga cuadrando.
 */
class DefectiveStockController extends Controller
{
    public const REFERENCE = 'DefectiveReview';
    /** Mercancía dañada o perdida que pasa a revisión (R8). */
    public const INTAKE_REFERENCE = 'ReviewIntake';

    public function __construct(private VariantStock $variants)
    {
    }

    /**
     * R8: la mercancía dañada o perdida (en un traslado o en el almacén) queda para revisión. POST { warehouse_id,
     * product_id, variant_id?, quantity, reason }. El total no cambia: las piezas siguen en el almacén, pero dejan de
     * venderse (defective_stock) hasta que el Admin las revise.
     */
    public function intake(Request $request, InventoryService $inventory): JsonResponse
    {
        if (!in_array(Auth::user()->role?->description, ['Admin', 'Master'], true)) {
            return response()->json(['status' => false, 'message' => 'Solo el administrador pasa piezas a revisión.'], 403);
        }
        $data = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'product_id'   => 'required|integer|exists:products,id',
            'variant_id'   => 'nullable|integer|exists:product_variants,id',
            'quantity'     => 'required|integer|min:1',
            'reason'       => 'required|string|max:500',
        ], ['reason.required' => 'Escribe qué le pasó: dañada en un traslado, perdida…']);
        $qty = (int) $data['quantity'];
        $variant = !empty($data['variant_id'])
            ? ProductVariant::where('product_id', $data['product_id'])->find($data['variant_id'])
            : null;
        if (!empty($data['variant_id']) && !$variant) {
            return response()->json(['status' => false, 'message' => 'Esa talla no es de este producto.'], 422);
        }

        $response = DB::transaction(function () use ($data, $qty, $variant) {
            $inv = Inventory::where('warehouse_id', $data['warehouse_id'])->where('product_id', $data['product_id'])->lockForUpdate()->first();
            if (!$inv) {
                return response()->json(['status' => false, 'message' => 'Ese almacén no tiene este producto.'], 422);
            }
            // Lo que se puede pasar: lo que no está ya en revisión, de esa talla o de lo que no tiene talla
            if ($variant) {
                $row = $this->variants->locked((int) $data['warehouse_id'], (int) $data['product_id'], $variant->id);
                $free = (int) $row->quantity - (int) $row->defective_stock;
            } else {
                $rows = InventoryVariant::where('warehouse_id', $data['warehouse_id'])->where('product_id', $data['product_id'])->get();
                $free = ((int) $inv->quantity - (int) $rows->sum('quantity')) - ((int) $inv->defective_stock - (int) $rows->sum('defective_stock'));
            }
            $product = Product::find($data['product_id']);
            $warehouse = Warehouse::find($data['warehouse_id']);
            $what = ($product->showable_name ?: $product->title) . ($variant ? " ({$variant->title})" : '');
            if ($free < $qty) {
                return response()->json(['status' => false, 'message' => "En {$warehouse->name} hay " . max(0, $free) . " pieza(s) de {$what} para pasar a revisión."], 422);
            }

            DB::table('inventories')->where('id', $inv->id)->update(['defective_stock' => $inv->defective_stock + $qty, 'updated_at' => now()]);
            if ($variant) {
                $this->variants->add((int) $data['warehouse_id'], (int) $data['product_id'], $variant->id, 0, $qty);
            }
            $old = (int) $inv->quantity;
            InventoryMovement::create([
                'product_id'        => $product->id,
                'variant_id'        => $variant?->id,
                'size'              => $variant?->title,
                'from_warehouse_id' => null,
                'to_warehouse_id'   => $warehouse->id,
                'quantity'          => $qty,
                'movement_type'     => 'adjustment',
                'status'            => 'completed',
                'reference_type'    => self::INTAKE_REFERENCE,
                'user_id'           => Auth::id(),
                // El total no cambia: "(Old/New)" con la misma cantidad, como lo lee inventory:audit
                'notes'             => "Pasa a revisión: {$qty} de {$what}. Motivo: " . trim($data['reason']) . " (Old: {$old}, New: {$old})",
            ]);

            return response()->json([
                'status'  => true,
                'message' => "Listo: {$qty} pieza(s) de {$what} quedan en revisión en {$warehouse->name}. No se venden hasta revisarlas.",
            ]);
        });

        // Lo vendible bajó: las órdenes de esa agencia que ya no alcanzan pasan a "Sin Stock", como con una salida
        if ($response->getStatusCode() === 200) {
            $inventory->recheckStock((int) $data['product_id'], (int) $data['warehouse_id'], true);
        }

        return $response;
    }

    /** POST { warehouse_id, product_id, variant_id?, quantity, action: restock|discard, notes? } */
    public function resolve(Request $request, InventoryService $inventory, DefectiveReview $review): JsonResponse
    {
        if (!in_array(Auth::user()->role?->description, ['Admin', 'Master'], true)) {
            return response()->json(['status' => false, 'message' => 'Solo el administrador revisa las piezas defectuosas.'], 403);
        }
        $data = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'product_id'   => 'required|integer|exists:products,id',
            'variant_id'   => 'nullable|integer|exists:product_variants,id',
            'quantity'     => 'required|integer|min:1',
            'action'       => 'required|in:restock,discard',
            'notes'        => 'nullable|string|max:500',
        ]);
        $qty = (int) $data['quantity'];
        $variant = !empty($data['variant_id'])
            ? ProductVariant::where('product_id', $data['product_id'])->find($data['variant_id'])
            : null;
        if (!empty($data['variant_id']) && !$variant) {
            return response()->json(['status' => false, 'message' => 'Esa talla no es de este producto.'], 422);
        }

        $out = $review->resolve((int) $data['warehouse_id'], (int) $data['product_id'], $variant, $qty, $data['action'], trim((string) ($data['notes'] ?? '')));

        // Lo vendible subió: las órdenes de esa agencia que estaban en "Sin Stock" por este producto pueden seguir
        if ($out['ok'] && $data['action'] === 'restock') {
            $inventory->recheckStock((int) $data['product_id'], (int) $data['warehouse_id'], false);
        }

        return response()->json(['status' => $out['ok'], 'message' => $out['message']], $out['code']);
    }
}
