<?php

namespace App\Services\Inventory;

use App\Models\City;
use App\Models\Inventory;
use App\Models\InventoryVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Support\Collection;

/**
 * Stock útil por ciudad (Fran, 2026-09-30): lo que tienen entre todas las agencias activas de la
 * ciudad, sin decir de qué agencia es. Lo usan la vendedora (inventario por ciudad) y el alta manual
 * (qué tallas hay). La decisión de a qué agencia va una orden sigue en AgencyRouter/StockCheck.
 */
class CityStock
{
    /** @return array<int, int[]>  city_id => almacenes activos de sus agencias activas. */
    public function warehousesByCity(?int $cityId = null): array
    {
        $cities = City::with(['agencies' => fn ($q) => $q->wherePivot('is_active', true)])
            ->when($cityId, fn ($q) => $q->whereKey($cityId))
            ->get();
        $warehouses = Warehouse::where('is_active', true)->whereNotNull('user_id')->pluck('id', 'user_id');

        $out = [];
        foreach ($cities as $city) {
            $out[$city->id] = $city->agencies->map(fn ($a) => $warehouses[$a->id] ?? null)->filter()->unique()->values()->all();
        }

        return $out;
    }

    /**
     * Stock de cada producto en una ciudad, sumando sus agencias.
     *
     * @param  int[]|null  $productIds  solo estos (aparecen aunque no haya nada); sin esto, los que tienen stock.
     * @return array<int, array{product_id:int, title:string, sku:?string, image:?string, total:int, unassigned:int,
     *         variants: array<int, array{id:int, title:string, available:int}>}>
     */
    public function forWarehouses(array $warehouseIds, ?array $productIds = null): array
    {
        $stock = $warehouseIds === [] ? collect() : Inventory::whereIn('warehouse_id', $warehouseIds)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->get()->groupBy('product_id');
        $variantStock = $warehouseIds === [] ? collect() : InventoryVariant::whereIn('warehouse_id', $warehouseIds)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->get()->groupBy('variant_id');

        $ids = $productIds ?? $stock->filter(fn (Collection $rows) => $rows->sum('useful_stock') > 0)->keys()->all();
        if ($ids === []) {
            return [];
        }
        $products = Product::whereIn('id', $ids)->get(['id', 'title', 'showable_name', 'sku', 'image'])->keyBy('id');
        $variants = ProductVariant::whereIn('product_id', $ids)->where('is_active', true)->orderBy('id')->get()->groupBy('product_id');

        $out = [];
        foreach ($ids as $id) {
            $product = $products->get($id);
            if (!$product) {
                continue;
            }
            $total = (int) ($stock->get($id)?->sum('useful_stock') ?? 0);
            $list = ($variants->get($id) ?? collect())->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'title' => $v->title,
                'available' => (int) ($variantStock->get($v->id)?->sum('useful_stock') ?? 0),
            ])->values()->all();
            $out[] = [
                'product_id' => $product->id,
                'title' => $product->showable_name ?: $product->title,
                'sku' => $product->sku,
                'image' => $product->image,
                'total' => $total,
                // Cargado sin decir la talla: no se vende por talla hasta repartirlo (tarea 4)
                'unassigned' => $list === [] ? 0 : max(0, $total - array_sum(array_column($list, 'available'))),
                'variants' => $list,
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['title'], $b['title']));

        return $out;
    }

    /**
     * ¿Alguna agencia de la ciudad tiene, ella sola, las piezas de esa variante? (una orden la entrega una
     * sola agencia). Devuelve lo que tiene la que más tiene.
     */
    public function bestVariantStock(array $warehouseIds, int $variantId): int
    {
        if ($warehouseIds === []) {
            return 0;
        }

        return (int) InventoryVariant::whereIn('warehouse_id', $warehouseIds)->where('variant_id', $variantId)->get()
            ->max('useful_stock');
    }
}
