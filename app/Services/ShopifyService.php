<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ShopifyService
{
    protected string $domain;
    protected string $token;
    protected string $version;

    /**
     * Constructor: puede recibir un Shop model para usar credenciales específicas,
     * o usar las credenciales por defecto del .env
     * 
     * @param \App\Models\Shop|null $shop
     */
    public function __construct($shop = null)
    {
        if ($shop) {
            // Usar credenciales de la tienda específica
            $this->domain = $shop->shopify_domain ?? '';
            $this->token = $shop->shopify_access_token ?? '';
            $this->version = config('shopify.api_version', '2025-07');
        } else {
            // Usar credenciales por defecto del .env
            $this->domain = config('shopify.domain', '');
            $this->token = config('shopify.api_token', '');
            $this->version = config('shopify.api_version', '2025-07');
        }

        // Validate that required configuration is present
        if (empty($this->domain) || empty($this->token)) {
            throw new \RuntimeException(
                'Shopify configuration is missing. Please ensure SHOPIFY_DOMAIN and SHOPIFY_API_TOKEN are set in your .env file or provide a valid Shop model.'
            );
        }
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
