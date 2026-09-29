<?php

namespace App\Services\Inventory;

use App\Models\Inventory;
use App\Models\InventoryVariant;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * ¿Alcanza el stock de un almacén para una orden? (tarea 4). Una sola regla para la ficha de la orden,
 * "Sin Stock", el reparto entre agencias y "dónde sí hay":
 *  - por producto, el stock útil del almacén (sin reservado, defectuoso ni bloqueado) cubre lo pedido;
 *  - por variante, lo de esa variante (sin sus defectuosas) cubre lo pedido de ella. Una talla sin stock
 *    ya no pasa porque el producto tenga otras (antes solo se miraba el total).
 */
class StockCheck
{
    /**
     * @return array{ok: bool, products: array<int, array{needed:int, available:int, has_stock:bool}>, lines: array<int, array{available:int, has_stock:bool}>}
     */
    public function at(Order $order, int $warehouseId): array
    {
        return $this->inWarehouses($order, [$warehouseId])[$warehouseId];
    }

    /** La misma revisión en varios almacenes, con dos consultas. */
    public function inWarehouses(Order $order, array $warehouseIds): array
    {
        $lines = $this->lines($order);
        $productIds = $lines->pluck('product_id')->unique()->values();
        $variantIds = $lines->pluck('variant_id')->filter()->unique()->values();

        $stock = $productIds->isEmpty() ? collect() : Inventory::whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $productIds)->get()->groupBy('warehouse_id');
        $variantStock = $variantIds->isEmpty() ? collect() : InventoryVariant::whereIn('warehouse_id', $warehouseIds)
            ->whereIn('variant_id', $variantIds)->get()->groupBy('warehouse_id');

        $out = [];
        foreach ($warehouseIds as $warehouseId) {
            $out[$warehouseId] = $this->evaluate(
                $lines,
                ($stock[$warehouseId] ?? collect())->keyBy('product_id'),
                ($variantStock[$warehouseId] ?? collect())->keyBy('variant_id'),
            );
        }

        return $out;
    }

    /** Siempre leídas de la base: una línea recién agregada o cambiada cuenta. */
    public function lines(Order $order): Collection
    {
        return $order->products()->get(['id', 'product_id', 'variant_id', 'quantity'])->map(fn ($op) => [
            'id' => (int) $op->id,
            'product_id' => (int) $op->product_id,
            'variant_id' => $op->variant_id ? (int) $op->variant_id : null,
            'quantity' => (int) $op->quantity,
        ])->filter(fn ($l) => $l['quantity'] > 0)->values();
    }

    private function evaluate(Collection $lines, Collection $stock, Collection $variantStock): array
    {
        $products = [];
        foreach ($lines->groupBy('product_id') as $productId => $group) {
            $available = (int) ($stock->get($productId)?->useful_stock ?? 0);
            $needed = (int) $group->sum('quantity');
            $products[$productId] = ['needed' => $needed, 'available' => $available, 'has_stock' => $available >= $needed];
        }

        $variants = [];
        foreach ($lines->whereNotNull('variant_id')->groupBy('variant_id') as $variantId => $group) {
            $available = (int) ($variantStock->get($variantId)?->useful_stock ?? 0);
            $needed = (int) $group->sum('quantity');
            $variants[$variantId] = ['available' => $available, 'has_stock' => $available >= $needed];
        }

        $out = [];
        $ok = true;
        foreach ($lines as $line) {
            $product = $products[$line['product_id']];
            $variant = $line['variant_id'] ? $variants[$line['variant_id']] : null;
            $hasStock = $product['has_stock'] && (!$variant || $variant['has_stock']);
            $out[$line['id']] = [
                'available' => $variant ? min($variant['available'], $product['available']) : $product['available'],
                'has_stock' => $hasStock,
            ];
            if (!$hasStock) {
                $ok = false;
                $products[$line['product_id']]['has_stock'] = false;
            }
        }

        // Si todo el producto es de una sola variante, su "disponible" es el de esa variante
        foreach ($lines->groupBy('product_id') as $productId => $group) {
            $variantIds = $group->pluck('variant_id')->unique();
            if ($variantIds->count() === 1 && $variantIds->first()) {
                $products[$productId]['available'] = $out[$group->first()['id']]['available'];
            }
        }

        return ['ok' => $ok, 'products' => $products, 'lines' => $out];
    }
}
