<?php

namespace App\Services\Inventory;

use App\Http\Controllers\DefectiveStockController;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\InventoryVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * La decisión del administrador sobre piezas en revisión (Módulo 4, R3 y R8; logística propia §16.1 punto 7 y §16.2.2
 * punto 8): "Vuelve a la venta" (restock) o "Dar de baja" (discard). La usan la pantalla de piezas defectuosas y la torre
 * de control con los retornos de los riders. Cada decisión queda como movimiento del producto.
 */
class DefectiveReview
{
    public function __construct(private VariantStock $variants)
    {
    }

    /** @return array{ok: bool, message: string, code: int} */
    public function resolve(int $warehouseId, int $productId, ?ProductVariant $variant, int $qty, string $action, string $note = ''): array
    {
        return DB::transaction(function () use ($warehouseId, $productId, $variant, $qty, $action, $note) {
            $inv = Inventory::where('warehouse_id', $warehouseId)->where('product_id', $productId)->lockForUpdate()->first();
            $have = (int) ($inv->defective_stock ?? 0);
            if ($have < $qty) {
                return ['ok' => false, 'message' => "En ese almacén hay {$have} pieza(s) defectuosa(s) de este producto.", 'code' => 422];
            }

            // Las defectuosas de una talla se revisan con su talla; las cargadas sin talla, sin ella
            $byVariant = (int) InventoryVariant::where('warehouse_id', $warehouseId)->where('product_id', $productId)->sum('defective_stock');
            if ($variant) {
                $row = InventoryVariant::where('warehouse_id', $warehouseId)->where('variant_id', $variant->id)->lockForUpdate()->first();
                $haveVariant = (int) ($row->defective_stock ?? 0);
                if ($haveVariant < $qty) {
                    return ['ok' => false, 'message' => "De la talla {$variant->title} hay {$haveVariant} defectuosa(s) en ese almacén.", 'code' => 422];
                }
            } elseif ($have - $byVariant < $qty) {
                return ['ok' => false, 'message' => 'Esas piezas tienen talla: elige la talla que revisaste.', 'code' => 422];
            }

            $old = (int) $inv->quantity;
            $discard = $action === 'discard';
            $new = $discard ? $old - $qty : $old;
            DB::table('inventories')->where('id', $inv->id)->update([
                'quantity'        => $new,
                'defective_stock' => $have - $qty,
                'updated_at'      => now(),
            ]);
            if ($variant) {
                $this->variants->add($warehouseId, $productId, $variant->id, $discard ? -$qty : 0, -$qty);
            }

            $product = Product::find($productId);
            $warehouse = Warehouse::find($warehouseId);
            $what = ($product->showable_name ?: $product->title) . ($variant ? " ({$variant->title})" : '');
            InventoryMovement::create([
                'product_id'        => $product->id,
                'variant_id'        => $variant?->id,
                'size'              => $variant?->title,
                'from_warehouse_id' => $discard ? $warehouse->id : null,
                'to_warehouse_id'   => $discard ? null : $warehouse->id,
                'quantity'          => $qty,
                'movement_type'     => $discard ? 'out' : 'adjustment',
                'status'            => 'completed',
                'reference_type'    => DefectiveStockController::REFERENCE,
                'user_id'           => Auth::id(),
                // El ajuste lleva "(Old/New)" con la misma cantidad: el total no cambia (inventory:audit lo lee así)
                'notes'             => ($discard
                        ? "Pieza defectuosa dada de baja: {$qty} de {$what}."
                        : "Pieza defectuosa revisada: {$qty} de {$what} vuelve a la venta. (Old: {$old}, New: {$new})")
                    . ($note !== '' ? " {$note}" : ''),
            ]);

            return [
                'ok' => true,
                'message' => $discard
                    ? "Dada de baja: {$qty} pieza(s) de {$what} salen del inventario de {$warehouse->name}."
                    : "Listo: {$qty} pieza(s) de {$what} vuelven a la venta en {$warehouse->name}.",
                'code' => 200,
            ];
        });
    }
}
