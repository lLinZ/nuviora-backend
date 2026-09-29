<?php

namespace App\Http\Controllers;

use App\Models\InventoryVariant;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

/**
 * Catálogo de variantes de cada producto (tarea 4). Las de Shopify se agregan solas al llegar un pedido;
 * aquí se ven, se crean a mano, se renombran y se desactivan. Una variante con stock o con pedidos no se
 * borra: se desactiva (deja de ofrecerse, y su historial queda).
 */
class ProductVariantController extends Controller
{
    public function index(Product $product)
    {
        return response()->json(['status' => true, 'data' => $this->rows($product)]);
    }

    public function store(Request $request, Product $product)
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'sku' => 'nullable|string|max:120',
        ]);
        if ($this->taken($product->id, $data['title'])) {
            return response()->json(['status' => false, 'message' => 'Ese producto ya tiene una variante con ese nombre.'], 422);
        }

        $variant = ProductVariant::create(['product_id' => $product->id] + $data);

        return response()->json(['status' => true, 'message' => "Variante {$variant->title} creada", 'data' => $this->rows($product)], 201);
    }

    public function update(Request $request, ProductVariant $variant)
    {
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:120',
            'sku' => 'nullable|string|max:120',
            'is_active' => 'sometimes|boolean',
        ]);
        if (isset($data['title']) && $this->taken($variant->product_id, $data['title'], $variant->id)) {
            return response()->json(['status' => false, 'message' => 'Ese producto ya tiene una variante con ese nombre.'], 422);
        }

        $variant->fill($data)->save();
        if ($variant->wasChanged('title')) {
            // El nombre que se lee en las órdenes abiertas sigue al catálogo
            OrderProduct::where('variant_id', $variant->id)->update(['size' => $variant->title]);
        }

        return response()->json(['status' => true, 'message' => 'Variante actualizada', 'data' => $this->rows($variant->product)]);
    }

    public function destroy(ProductVariant $variant)
    {
        $inUse = InventoryVariant::where('variant_id', $variant->id)->where(fn ($q) => $q->where('quantity', '<>', 0)->orWhere('defective_stock', '<>', 0))->exists()
            || OrderProduct::where('variant_id', $variant->id)->exists();
        if ($inUse) {
            return response()->json([
                'status' => false,
                'message' => 'Tiene stock o pedidos: en vez de borrarla, desactívala.',
            ], 422);
        }

        $product = $variant->product;
        InventoryVariant::where('variant_id', $variant->id)->delete();
        $variant->delete();

        return response()->json(['status' => true, 'message' => 'Variante borrada', 'data' => $this->rows($product)]);
    }

    private function taken(int $productId, string $title, ?int $exceptId = null): bool
    {
        return ProductVariant::where('product_id', $productId)
            ->where('key', ProductVariant::keyFor($title))
            ->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))
            ->exists();
    }

    /** Las variantes del producto, con su stock total y en cuántas órdenes están. */
    private function rows(Product $product): array
    {
        $stock = InventoryVariant::where('product_id', $product->id)->selectRaw('variant_id, SUM(quantity) q')->groupBy('variant_id')->pluck('q', 'variant_id');
        $orders = OrderProduct::where('product_id', $product->id)->whereNotNull('variant_id')
            ->selectRaw('variant_id, COUNT(*) n')->groupBy('variant_id')->pluck('n', 'variant_id');

        return $product->variants()->get()->map(fn (ProductVariant $v) => [
            'id' => $v->id,
            'title' => $v->title,
            'sku' => $v->sku,
            'shopify_variant_id' => $v->shopify_variant_id ? (string) $v->shopify_variant_id : null,
            'is_active' => $v->is_active,
            'stock' => (int) ($stock[$v->id] ?? 0),
            'order_lines' => (int) ($orders[$v->id] ?? 0),
        ])->all();
    }
}
