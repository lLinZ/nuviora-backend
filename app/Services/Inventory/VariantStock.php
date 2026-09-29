<?php

namespace App\Services\Inventory;

use App\Models\InventoryVariant;
use App\Models\ProductVariant;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock por variante (tarea 4), en filas de inventory_variants. Cada cambio bloquea su fila, así dos
 * ventas a la vez ya no se pisan (antes era un JSON que se reescribía entero).
 * inventories.quantity sigue siendo el total del producto e incluye esto: quien mueve una variante mueve
 * también el total. Lo que el total tiene de más es stock "sin variante".
 */
class VariantStock
{
    /** Suma (o resta) a la variante en ese almacén. Puede quedar en negativo, para que se vea. */
    public function add(int $warehouseId, int $productId, int $variantId, int $delta, int $defectiveDelta = 0): void
    {
        $row = $this->locked($warehouseId, $productId, $variantId);
        DB::table('inventory_variants')->where('id', $row->id)->update([
            'quantity' => $row->quantity + $delta,
            'defective_stock' => max(0, $row->defective_stock + $defectiveDelta),
            'updated_at' => now(),
        ]);
    }

    /** Fija la cantidad de la variante. Devuelve la que tenía. */
    public function set(int $warehouseId, int $productId, int $variantId, int $quantity): int
    {
        $row = $this->locked($warehouseId, $productId, $variantId);
        DB::table('inventory_variants')->where('id', $row->id)->update(['quantity' => $quantity, 'updated_at' => now()]);

        return (int) $row->quantity;
    }

    public function locked(int $warehouseId, int $productId, int $variantId): InventoryVariant
    {
        $find = fn () => InventoryVariant::where('warehouse_id', $warehouseId)->where('variant_id', $variantId)->lockForUpdate()->first();
        $row = $find();
        if (!$row) {
            InventoryVariant::insertOrIgnore([
                'warehouse_id' => $warehouseId, 'product_id' => $productId, 'variant_id' => $variantId,
                'quantity' => 0, 'defective_stock' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = $find();
        }

        return $row;
    }

    /** @return Collection<int, InventoryVariant> por variant_id */
    public function rows(int $warehouseId, int $productId): Collection
    {
        return InventoryVariant::where('warehouse_id', $warehouseId)->where('product_id', $productId)->get()->keyBy('variant_id');
    }

    /** Suma de lo repartido por variante en ese almacén. */
    public function assigned(int $warehouseId, int $productId): int
    {
        return (int) InventoryVariant::where('warehouse_id', $warehouseId)->where('product_id', $productId)->sum('quantity');
    }

    /**
     * Desglose para mostrar, por "almacén|producto".
     *
     * @return Collection<string, Collection<int, object>>
     */
    public function breakdown(?array $warehouseIds = null, ?array $productIds = null): Collection
    {
        return InventoryVariant::query()
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->get(['warehouse_id', 'product_id', 'variant_id', 'quantity', 'defective_stock'])
            ->groupBy(fn ($r) => "{$r->warehouse_id}|{$r->product_id}");
    }

    /**
     * Stock por variante de un producto en un almacén, para la pantalla: todas sus variantes (las inactivas
     * solo si les queda stock) y lo que está "sin variante" (el total menos lo repartido).
     *
     * @param  Collection<int, ProductVariant>  $variants  las del producto
     * @return array{variants_stock: array, unassigned: int}
     */
    public function present(int $total, Collection $variants, ?Collection $rows): array
    {
        $rows = ($rows ?? collect())->keyBy('variant_id');
        $list = [];
        foreach ($variants as $variant) {
            $row = $rows->get($variant->id);
            if (!$variant->is_active && (!$row || (int) $row->quantity === 0)) {
                continue;
            }
            $list[] = [
                'variant_id' => $variant->id,
                'title' => $variant->title,
                'is_active' => (bool) $variant->is_active,
                'quantity' => (int) ($row->quantity ?? 0),
                'defective_stock' => (int) ($row->defective_stock ?? 0),
            ];
        }

        return ['variants_stock' => $list, 'unassigned' => $total - (int) $rows->sum('quantity')];
    }

    /**
     * Lo que manda la pantalla, como [variant_id => cantidad]. Acepta {"12": 3}, [{"variant_id": 12, "quantity": 3}]
     * o el formato viejo por nombre de talla ({"S/M": 3}). Las variantes tienen que ser del producto.
     *
     * @return array<int, int>
     */
    public function parse(int $productId, $input, bool $allowZero = false): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (!is_array($input) || !$input) {
            return [];
        }

        $variants = ProductVariant::where('product_id', $productId)->get();
        $out = [];
        foreach ($input as $k => $v) {
            if (is_array($v)) {
                $ref = $v['variant_id'] ?? $v['id'] ?? $v['title'] ?? null;
                $qty = $v['quantity'] ?? $v['qty'] ?? 0;
            } else {
                $ref = $k;
                $qty = $v;
            }
            $qty = (int) $qty;
            if ($qty < 0) {
                throw new Exception('Las cantidades por variante no pueden ser negativas.');
            }
            if ($qty === 0 && !$allowZero) {
                continue;
            }
            // Por id; si no, por nombre (una talla puede ser un número: "38"). Un nombre que no está en el
            // catálogo no se inventa aquí: un error de tipeo crearía una talla nueva
            $variant = is_numeric($ref) ? $variants->firstWhere('id', (int) $ref) : null;
            if (!$variant && $ref !== null) {
                $variant = $variants->firstWhere('key', ProductVariant::keyFor((string) $ref));
            }
            if (!$variant) {
                throw new Exception("La variante {$ref} no es de este producto: créala primero en Tallas y variantes.");
            }
            $out[$variant->id] = ($out[$variant->id] ?? 0) + $qty;
        }

        return $out;
    }
}
