<?php

namespace App\Services\Inventory;

use App\Models\ProductVariant;
use Illuminate\Database\QueryException;

/**
 * Catálogo de variantes (tarea 4). Una variante se reconoce por su id de Shopify o por su nombre
 * normalizado ("S/M" = "s / m" = "Talla S/M"). Si no existe, se agrega: así el catálogo crece con lo que
 * llega de Shopify, igual que antes crecían las tallas, pero sin crear una talla distinta por cada forma
 * de escribirla.
 */
class VariantCatalog
{
    public function resolve(int $productId, ?string $title, ?int $shopifyVariantId = null, ?string $sku = null): ?ProductVariant
    {
        $variant = $shopifyVariantId
            ? ProductVariant::where('product_id', $productId)->where('shopify_variant_id', $shopifyVariantId)->first()
            : null;

        $key = ProductVariant::keyFor($title);
        if (!$variant && $key !== '') {
            $variant = ProductVariant::where('product_id', $productId)->where('key', $key)->first();
        }

        if (!$variant) {
            if ($key === '') {
                return null;
            }
            try {
                $variant = ProductVariant::create([
                    'product_id' => $productId,
                    'title' => $title,
                    'shopify_variant_id' => $shopifyVariantId,
                    'sku' => $sku,
                ]);
            } catch (QueryException $e) {
                // Otra petición la creó al mismo tiempo
                $variant = ProductVariant::where('product_id', $productId)->where('key', $key)->firstOrFail();
            }
        }

        // Completa lo que falte (una talla creada a mano que después llega de Shopify)
        $fill = [];
        if ($shopifyVariantId && !$variant->shopify_variant_id) {
            $fill['shopify_variant_id'] = $shopifyVariantId;
        }
        if ($sku && !$variant->sku) {
            $fill['sku'] = $sku;
        }
        if ($fill) {
            $variant->update($fill);
        }

        return $variant;
    }

    /** La variante de Shopify, en cualquier producto. */
    public function byShopifyId(?int $shopifyVariantId): ?ProductVariant
    {
        return $shopifyVariantId ? ProductVariant::where('shopify_variant_id', $shopifyVariantId)->first() : null;
    }
}
