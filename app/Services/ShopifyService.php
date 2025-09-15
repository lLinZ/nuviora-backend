<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ShopifyService
{
    protected string $domain;
    protected string $token;
    protected string $version = '2025-07'; // versión de la API

    public function __construct()
    {
        $this->domain = env('SHOPIFY_DOMAIN'); // ej: nuviora.myshopify.com
        $this->token = env('SHOPIFY_API_TOKEN');
    }

    /**
     * Obtiene un producto desde Shopify por su ID.
     */
    public function getProductById($productId): ?array
    {
        $url = "https://{$this->domain}/admin/api/{$this->version}/products/{$productId}.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->token,
        ])->get($url);

        if ($response->successful()) {
            return $response->json()['product'];
        }

        return null;
    }

    /**
     * Obtiene la imagen principal de un producto/variante.
     */
    public function getProductImage($productId, $variantId = null): ?string
    {
        $product = $this->getProductById($productId);

        if (!$product) {
            return null;
        }

        // Buscar la variante
        if ($variantId) {
            $variant = collect($product['variants'])->firstWhere('id', $variantId);

            if ($variant && !empty($variant['image_id'])) {
                $image = collect($product['images'])->firstWhere('id', $variant['image_id']);
                return $image['src'] ?? null;
            }
        }

        // Si no encontró imagen por variante, tomar la primera imagen
        return $product['images'][0]['src'] ?? null;
    }
}
