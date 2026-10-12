<?php

use App\Models\AgencyTariff;
use App\Models\AgencyTariffRange;
use App\Services\Delivery\GoogleRoutesClient;
use App\Services\Delivery\GoogleRoutesException;
use App\Services\Delivery\MapsLinkResolver;
use App\Services\Delivery\PlusCode;
use App\Services\Delivery\TariffBook;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Módulo 3 de Fran (tarifas de delivery): rangos (§9, §11, casos 1 a 3 del §47), links de Maps (§7) y Google Routes
// (§3, §4, §42, §44), sin base de datos ni Internet.

/** Un tarifario en memoria con los rangos dados: [min, max|null, precio|null, por km|null]. */
function tarifario(array $rangos): AgencyTariff
{
    $t = new AgencyTariff();
    $t->setRelation('ranges', collect($rangos)->map(fn ($r, $i) => new AgencyTariffRange([
        'position' => $i + 1, 'min_km' => $r[0], 'max_km' => $r[1], 'price' => $r[2], 'price_per_km' => $r[3] ?? null,
    ])));
    return $t;
}

describe('Rangos del tarifario (§9, §11)', function () {
    $ejemplo = fn () => tarifario([[0, 5, 3], [5, 10, 4], [10, 15, 5]]); // el ejemplo del §10

    it('caso 1: 4,5 km cae en el rango 0–5', function () use ($ejemplo) {
        expect($ejemplo()->rangeFor(4500)->price)->toBe(3.0);
    });

    it('caso 2: exactamente 5 km es del rango 1', function () use ($ejemplo) {
        expect($ejemplo()->rangeFor(5000)->price)->toBe(3.0);
    });

    it('caso 3: 5,001 km ya es del rango 2 (no se redondea a 5)', function () use ($ejemplo) {
        expect($ejemplo()->rangeFor(5001)->price)->toBe(4.0);
        expect($ejemplo()->rangeFor(10000)->price)->toBe(4.0);
        expect($ejemplo()->rangeFor(10001)->price)->toBe(5.0);
    });

    it('0 metros (el cliente en el almacén) es del primer rango', function () use ($ejemplo) {
        expect($ejemplo()->rangeFor(0)->price)->toBe(3.0);
    });

    it('más allá del último rango no hay precio (no se inventa)', function () use ($ejemplo) {
        expect($ejemplo()->rangeFor(15001))->toBeNull();
    });

    it('el tarifario de PYP: desde 20 km, $4 más $0,20 por cada km extra (Fran: "21 km serían 20 y uno extra")', function () {
        $pyp = tarifario([[0, 7, 2], [7, 14, 3], [14, 20, 4], [20, null, 4, 0.20]]);
        expect($pyp->rangeFor(7000)->priceFor(7000))->toBe(2.0);
        expect($pyp->rangeFor(7100)->priceFor(7100))->toBe(3.0);
        expect($pyp->rangeFor(20000)->priceFor(20000))->toBe(4.0);
        expect($pyp->rangeFor(21000)->priceFor(21000))->toBe(4.2);
        expect($pyp->rangeFor(21500)->priceFor(21500))->toBe(4.3);
        expect($pyp->rangeFor(22000)->priceFor(22000))->toBe(4.4);
        expect($pyp->rangeFor(40000)->priceFor(40000))->toBe(8.0);
    });

    it('el km extra se cuenta desde el "desde" del rango, no sobre todo el recorrido', function () {
        $otra = tarifario([[0, 20, 5], [20, null, 5, 0.30]]);
        expect($otra->rangeFor(21000)->priceFor(21000))->toBe(5.3); // no 21 × 0,30 = 6,30
    });

    it('la tarifa fija de Rapi2 es un solo rango abierto', function () {
        expect(tarifario([[0, null, 3]])->rangeFor(35000)->price)->toBe(3.0);
    });
});

describe('Validación de rangos (§11)', function () {
    $r = fn ($min, $max, $price = 3, $perKm = null) => ['min_km' => $min, 'max_km' => $max, 'price' => $price, 'price_per_km' => $perKm];

    it('acepta rangos continuos', function () use ($r) {
        expect(TariffBook::rangeErrors([$r(0, 5), $r(5, 10), $r(10, null, 4, 0.2)]))->toBeNull();
    });

    it('rechaza huecos, solapes, negativos, desde ≥ hasta y un "en adelante" que no sea el último', function () use ($r) {
        expect(TariffBook::rangeErrors([$r(0, 5), $r(6, 10)]))->toContain('hueco');
        expect(TariffBook::rangeErrors([$r(0, 5), $r(4, 10)]))->toContain('solape');
        expect(TariffBook::rangeErrors([$r(0, 5), $r(5, 5)]))->toContain('menor');
        expect(TariffBook::rangeErrors([$r(0, 5, -1)]))->toContain('negativos');
        expect(TariffBook::rangeErrors([$r(1, 5)]))->toContain('empezar en 0');
        expect(TariffBook::rangeErrors([$r(0, null), $r(5, 10)]))->toContain('solo el último');
        expect(TariffBook::rangeErrors([$r(0, 5), $r(0, 5)]))->not->toBeNull(); // duplicado
        expect(TariffBook::rangeErrors([['min_km' => 0, 'max_km' => null, 'price' => null, 'price_per_km' => 0.2]]))->toContain('falta el precio');
        expect(TariffBook::rangeErrors([]))->not->toBeNull();
    });
});

describe('Links de Google Maps (§7)', function () {
    $resolver = fn () => new MapsLinkResolver();

    it('lee el punto marcado de un lugar (!3d!4d), no el centro del mapa', function () use ($resolver) {
        $url = 'https://www.google.com/maps/place/P%C3%ADdelo+y+Punto+Delivery/@10.4841938,-66.8838054,1093m/data=!3m1!1e3!4m6!3m5!1s0x8c2a:0x75!8m2!3d10.4841938!4d-66.8812251!16s';
        expect($resolver()->resolve($url))->toMatchArray(['status' => 'ok', 'lat' => 10.4841938, 'lng' => -66.8812251]);
    });

    it('lee ?q=lat,lng (la ubicación que comparte WhatsApp)', function () use ($resolver) {
        expect($resolver()->resolve('Ubicación: https://maps.google.com/?q=10.4806,-66.9036'))->toMatchArray(['status' => 'ok', 'lat' => 10.4806, 'lng' => -66.9036]);
    });

    it('lee coordenadas sueltas', function () use ($resolver) {
        expect($resolver()->resolve('10.2692573, -67.5922725'))->toMatchArray(['status' => 'ok', 'lat' => 10.2692573]);
    });

    it('un lugar o una búsqueda sin coordenadas queda pendiente de resolver, sin API de Google', function () use ($resolver) {
        expect($resolver()->resolve('https://www.google.com/maps/search/Centro+Comercial+Sambil')['status'])->toBe('pending');
    });

    it('lo que no es de Maps es inválido', function () use ($resolver) {
        expect($resolver()->resolve('Calle 5, casa azul')['status'])->toBe('invalid');
        expect($resolver()->resolve('https://instagram.com/algo')['status'])->toBe('invalid');
    });

    it('coordenadas fuera de Venezuela son inválidas (lat y lng al revés)', function () use ($resolver) {
        expect($resolver()->resolve('https://maps.google.com/?q=-66.9036,10.4806')['status'])->toBe('invalid');
    });

    it('sigue la redirección del enlace corto sin descargar la página', function () use ($resolver) {
        Http::fake(['maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/x/@10.2,-67.5,15z/data=!3d10.2692573!4d-67.5922725'])]);
        expect($resolver()->resolve('https://maps.app.goo.gl/iRHPtYh8c1STYG4A8'))->toMatchArray(['status' => 'ok', 'lat' => 10.2692573, 'lng' => -67.5922725]);
        expect(MapsLinkResolver::isShortLink('mira: https://maps.app.goo.gl/abc'))->toBeTrue();
    });

    it('si el enlace corto no abre, queda pendiente (se reintenta)', function () use ($resolver) {
        Http::fake(['maps.app.goo.gl/*' => Http::response('', 404)]);
        expect($resolver()->resolve('https://maps.app.goo.gl/nada')['status'])->toBe('pending');
    });

    // Producción, 2026-10-12: 46 de las 110 ubicaciones sin leer eran lugares sin nombre, que Google llama por su código
    // plus. El punto de PYP (10.4841938, -66.8812251) es 772MF4M9+MG; el corto, F4M9+MG.
    it('un lugar que Google llama por su código plus corto se lee con el almacén de referencia', function () use ($resolver) {
        $url = 'https://www.google.com/maps/place/F4M9%2BMG+Caracas,+Distrito+Capital/data=!4m2!3m1!1s0x0:0x0?entry=tts';
        $r = $resolver()->resolve($url, true, [10.48, -66.90]);
        expect($r['status'])->toBe('ok')
            ->and(abs($r['lat'] - 10.4841938))->toBeLessThan(0.0002)
            ->and(abs($r['lng'] + 66.8812251))->toBeLessThan(0.0002);
    });

    it('el enlace corto que lleva a un código plus también', function () use ($resolver) {
        Http::fake(['maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/F4M9%2BMG+Caracas/data=!4m2?g_st=iw'])]);
        expect($resolver()->resolve('https://maps.app.goo.gl/plus', true, [10.48, -66.90])['status'])->toBe('ok');
    });

    it('sin almacén de referencia, el código corto queda pendiente (no se adivina)', function () use ($resolver) {
        $r = $resolver()->resolve('https://www.google.com/maps/place/F4M9%2BMG+Caracas/data=!4m2');
        expect($r['status'])->toBe('pending')->and($r['note'])->toContain('Código plus');
    });

    it('el código plus completo escrito a mano no necesita referencia', function () use ($resolver) {
        expect($resolver()->resolve('772MF4M9+MG')['status'])->toBe('ok');
    });

    it('un lugar con nombre sigue pendiente: no se inventa la ubicación', function () use ($resolver) {
        expect($resolver()->resolve('https://www.google.com/maps/place/Residencias+Los+Samanes,+Caracas/data=!4m2', true, [10.48, -66.90])['status'])->toBe('pending');
    });
});

describe('Códigos plus (Open Location Code)', function () {
    it('decodifica el ejemplo de la especificación (8FVC9G8F+6X, Zúrich)', function () {
        expect(PlusCode::decode('8FVC9G8F+6X'))->toBe([47.3655625, 8.5249375]);
    });

    it('completa el corto con el punto más cercano (recoverNearest)', function () {
        expect(PlusCode::recoverNearest('9G8F+6X', 47.4, 8.6))->toBe([47.3655625, 8.5249375]);
    });

    it('con una referencia de otra ciudad (a más de medio grado) caería en otro sitio: por eso es la de la agencia de la ciudad', function () {
        expect(PlusCode::recoverNearest('F4M9+MG', 10.16, -67.97)[1])->toBeLessThan(-67.5);
    });

    it('no confunde otras cosas del link con un código plus', function () {
        expect(PlusCode::find('g_ep=EgoyMDI2MTAwNi4wIPu8ASoASAFQAw'))->toBeNull()
            ->and(PlusCode::find('Torre 7, piso 3+ Caracas'))->toBeNull()
            ->and(PlusCode::find('ubicación: F4M9+MG Caracas'))->toBe('F4M9+MG');
    });
});

describe('Google Routes (§3, §4, §42, §44)', function () {
    beforeEach(fn () => config(['services.google_routes.key' => 'clave-de-prueba-123']));

    it('pide la ruta como dice el §4 y solo routes.distanceMeters', function () {
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => 7248]]])]);

        expect((new GoogleRoutesClient())->distanceMeters(10.48, -66.88, 10.50, -66.90))->toBe(7248);

        Http::assertSent(function (Request $r) {
            $b = $r->data();
            return $r->method() === 'POST' && $r->url() === GoogleRoutesClient::URL
                && $r->header('X-Goog-FieldMask')[0] === 'routes.distanceMeters'
                && $r->header('X-Goog-Api-Key')[0] === 'clave-de-prueba-123'
                && $b['travelMode'] === 'DRIVE' && $b['routingPreference'] === 'TRAFFIC_UNAWARE'
                && $b['computeAlternativeRoutes'] === false && $b['units'] === 'METRIC'
                && $b['routeModifiers'] === ['avoidTolls' => false, 'avoidHighways' => false, 'avoidFerries' => false]
                && $b['origin']['location']['latLng'] === ['latitude' => 10.48, 'longitude' => -66.88];
        });
    });

    it('sin distanceMeters en la ruta es 0 (Google no manda los ceros)', function () {
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [new stdClass()]])]);
        expect((new GoogleRoutesClient())->distanceMeters(10.48, -66.88, 10.48, -66.88))->toBe(0);
    });

    it('caso 16: si Google falla no hay distancia; la clave mala no se reintenta y la red sí', function () {
        Http::fake(['routes.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'API key not valid']], 403)
            ->push(['error' => ['status' => 'UNAVAILABLE']], 503)
            ->push([], 200)]);
        $client = new GoogleRoutesClient();
        $kinds = [];
        foreach (range(1, 3) as $_) {
            try {
                $client->distanceMeters(10.48, -66.88, 10.5, -66.9);
            } catch (GoogleRoutesException $e) {
                $kinds[] = [$e->kind, $e->retryable()];
                expect($e->getMessage())->not->toContain('clave-de-prueba-123');
            }
        }
        expect($kinds)->toBe([['auth', false], ['network', true], ['no_route', false]]);
    });

    it('sin clave no llama a Google', function () {
        config(['services.google_routes.key' => '']);
        Http::fake();
        expect(fn () => (new GoogleRoutesClient())->distanceMeters(1, 1, 1, 1))->toThrow(GoogleRoutesException::class);
        Http::assertNothingSent();
    });
});
