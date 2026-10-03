<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\InventoryVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Inventory\VariantStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Piezas defectuosas (Fran, 2026-10-02). La pieza que vuelve de un cambio queda en el almacén de la
 * agencia como defectuosa: existe, pero no se cuenta como disponible. Después de revisarla, el
 * administrador decide:
 *  - "Vuelve a la venta" (restock): estaba bien; deja de ser defectuosa y se puede vender.
 *  - "Dar de baja" (discard): está dañada; sale del inventario.
 * Cada decisión queda como movimiento del producto, para que el inventario siga cuadrando.
 */
class DefectiveStockController extends Controller
{
    public const REFERENCE = 'DefectiveReview';

    public function __construct(private VariantStock $variants)
    {
    }

    /** POST { warehouse_id, product_id, variant_id?, quantity, action: restock|discard, notes? } */
    public function resolve(Request $request): JsonResponse
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

        return DB::transaction(function () use ($data, $qty, $variant) {
            $inv = Inventory::where('warehouse_id', $data['warehouse_id'])->where('product_id', $data['product_id'])->lockForUpdate()->first();
            $have = (int) ($inv->defective_stock ?? 0);
            if ($have < $qty) {
                return response()->json(['status' => false, 'message' => "En ese almacén hay {$have} pieza(s) defectuosa(s) de este producto."], 422);
            }

            // Las defectuosas de una talla se revisan con su talla; las cargadas sin talla, sin ella
            $byVariant = (int) InventoryVariant::where('warehouse_id', $data['warehouse_id'])->where('product_id', $data['product_id'])->sum('defective_stock');
            if ($variant) {
                $row = InventoryVariant::where('warehouse_id', $data['warehouse_id'])->where('variant_id', $variant->id)->lockForUpdate()->first();
                $haveVariant = (int) ($row->defective_stock ?? 0);
                if ($haveVariant < $qty) {
                    return response()->json(['status' => false, 'message' => "De la talla {$variant->title} hay {$haveVariant} defectuosa(s) en ese almacén."], 422);
                }
            } elseif ($have - $byVariant < $qty) {
                return response()->json(['status' => false, 'message' => 'Esas piezas tienen talla: elige la talla que revisaste.'], 422);
            }

            $old = (int) $inv->quantity;
            $discard = $data['action'] === 'discard';
            $new = $discard ? $old - $qty : $old;
            DB::table('inventories')->where('id', $inv->id)->update([
                'quantity'        => $new,
                'defective_stock' => $have - $qty,
                'updated_at'      => now(),
            ]);
            if ($variant) {
                $this->variants->add((int) $data['warehouse_id'], (int) $data['product_id'], $variant->id, $discard ? -$qty : 0, -$qty);
            }

            $product = Product::find($data['product_id']);
            $warehouse = Warehouse::find($data['warehouse_id']);
            $what = ($product->showable_name ?: $product->title) . ($variant ? " ({$variant->title})" : '');
            $note = trim((string) ($data['notes'] ?? ''));
            InventoryMovement::create([
                'product_id'        => $product->id,
                'variant_id'        => $variant?->id,
                'size'              => $variant?->title,
                'from_warehouse_id' => $discard ? $warehouse->id : null,
                'to_warehouse_id'   => $discard ? null : $warehouse->id,
                'quantity'          => $qty,
                'movement_type'     => $discard ? 'out' : 'adjustment',
                'status'            => 'completed',
                'reference_type'    => self::REFERENCE,
                'user_id'           => Auth::id(),
                // El ajuste lleva "(Old/New)" con la misma cantidad: el total no cambia (inventory:audit lo lee así)
                'notes'             => ($discard
                        ? "Pieza defectuosa dada de baja: {$qty} de {$what}."
                        : "Pieza defectuosa revisada: {$qty} de {$what} vuelve a la venta. (Old: {$old}, New: {$new})")
                    . ($note !== '' ? " {$note}" : ''),
            ]);

            return response()->json([
                'status'  => true,
                'message' => $discard
                    ? "Dada de baja: {$qty} pieza(s) de {$what} salen del inventario de {$warehouse->name}."
                    : "Listo: {$qty} pieza(s) de {$what} vuelven a la venta en {$warehouse->name}.",
            ]);
        });
    }
}
