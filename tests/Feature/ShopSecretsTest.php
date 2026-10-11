<?php

use App\Models\Shop;

// El token de Shopify y el secreto de los webhooks no salen en ninguna respuesta (antes GET /api/shops los daba a
// cualquier usuario con sesión).

it('al serializar una tienda no salen el token ni el secreto, solo si están cargados', function () {
    $shop = new Shop(['name' => 'Tienda', 'shopify_access_token' => 'shpat_secreto123', 'shopify_webhook_secret' => 'whsec_456']);
    $json = $shop->toJson();

    expect($json)->not->toContain('shpat_secreto123')->not->toContain('whsec_456');
    expect($shop->toArray())->not->toHaveKeys(['shopify_access_token', 'shopify_webhook_secret'])
        ->and($shop->toArray()['has_access_token'])->toBeTrue()
        ->and($shop->toArray()['has_webhook_secret'])->toBeTrue();
});

it('sin token, lo dice', function () {
    $shop = new Shop(['name' => 'Tienda', 'shopify_access_token' => '  ']);
    expect($shop->has_access_token)->toBeFalse()->and($shop->has_webhook_secret)->toBeFalse();
});

it('el backend lo sigue pudiendo leer', function () {
    expect((new Shop(['shopify_access_token' => 'shpat_x']))->shopify_access_token)->toBe('shpat_x');
});
