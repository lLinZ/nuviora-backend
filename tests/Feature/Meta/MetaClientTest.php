<?php

use App\Models\MetaConnection;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Módulo 2 de Fran (Meta Ads): §1 solo lectura, §4 el token nunca sale, §52 límites, §53 versión, §54 errores.

const TOKEN_FALSO = 'EAAtokenFalsoDePrueba123';

describe('Cliente de Meta', function () {
    it('solo tiene métodos para leer (§1)', function () {
        $publicos = collect((new ReflectionClass(MetaClient::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map->getName()->sort()->values()->all();
        expect($publicos)->toBe(['__construct', 'calls', 'for', 'get', 'getAll', 'minutesToRegainAccess', 'usage', 'usagePercent', 'version']);
    });

    it('pide con GET, con la versión configurada y el token solo en la cabecera (§1, §4, §53)', function () {
        config(['services.meta.graph_version' => 'v26.0']);
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1', 'name' => 'BM1'])]);

        (new MetaClient(TOKEN_FALSO))->get('me', ['fields' => 'id,name']);

        Http::assertSent(function (Request $r) {
            return $r->method() === 'GET'
                && str_starts_with($r->url(), 'https://graph.facebook.com/v26.0/me?')
                && !str_contains($r->url(), TOKEN_FALSO)
                && $r->header('Authorization')[0] === 'Bearer ' . TOKEN_FALSO;
        });
    });

    it('sigue la paginación sin el token que Meta pone en el enlace', function () {
        Http::fake([
            'graph.facebook.com/v26.0/act_1/ads?*after=B*' => Http::response(['data' => [['id' => '3']]]),
            'graph.facebook.com/v26.0/act_1/ads*' => Http::response([
                'data' => [['id' => '1'], ['id' => '2']],
                'paging' => ['next' => 'https://graph.facebook.com/v26.0/act_1/ads?limit=2&after=B&access_token=' . TOKEN_FALSO],
            ]),
        ]);

        $filas = (new MetaClient(TOKEN_FALSO, null, 'v26.0'))->getAll('act_1/ads', ['limit' => 2]);

        expect(array_column($filas, 'id'))->toBe(['1', '2', '3']);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            expect($request->method())->toBe('GET');
            expect($request->url())->not->toContain(TOKEN_FALSO);
        }
    });

    it('agrega el appsecret_proof si la conexión tiene App Secret', function () {
        Http::fake(['*' => Http::response(['id' => '1'])]);
        (new MetaClient(TOKEN_FALSO, 'secreto', 'v26.0'))->get('me');
        Http::assertSent(fn (Request $r) => $r['appsecret_proof'] === hash_hmac('sha256', TOKEN_FALSO, 'secreto'));
    });

    it('manda las listas como JSON', function () {
        Http::fake(['*' => Http::response(['data' => []])]);
        (new MetaClient(TOKEN_FALSO, null, 'v26.0'))->get('act_1/ads', ['effective_status' => ['ACTIVE', 'PAUSED'], 'use_unified_attribution_setting' => true]);
        Http::assertSent(fn (Request $r) => $r['effective_status'] === '["ACTIVE","PAUSED"]' && $r['use_unified_attribution_setting'] === 'true');
    });

    it('nunca deja el token en el mensaje de error (§4)', function () {
        Http::fake(['*' => Http::response(['error' => [
            'message' => 'Invalid OAuth access token ' . TOKEN_FALSO, 'type' => 'OAuthException', 'code' => 190, 'fbtrace_id' => 'XYZ',
        ]], 401)]);

        try {
            (new MetaClient(TOKEN_FALSO, null, 'v26.0'))->get('me');
            $this->fail('debía fallar');
        } catch (MetaApiException $e) {
            expect($e->kind)->toBe(MetaApiException::TOKEN);
            expect($e->getMessage())->not->toContain(TOKEN_FALSO)->toContain('***');
            expect($e->fbtraceId)->toBe('XYZ');
            expect($e->httpStatus)->toBe(401);
        }
    });

    it('convierte un corte de conexión en timeout, sin el token (§54)', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 Authorization: Bearer ' . TOKEN_FALSO));
        try {
            (new MetaClient(TOKEN_FALSO, null, 'v26.0'))->get('me');
            $this->fail('debía fallar');
        } catch (MetaApiException $e) {
            expect($e->kind)->toBe(MetaApiException::TIMEOUT);
            expect($e->isTransient())->toBeTrue();
            expect($e->getMessage())->not->toContain(TOKEN_FALSO);
        }
    });

    it('lee el uso del límite que informa Meta (§52)', function () {
        Http::fake(['*' => Http::response(['id' => '1'], 200, [
            'x-business-use-case-usage' => '{"123":[{"type":"ads_insights","call_count":42,"total_cputime":7,"total_time":12,"estimated_time_to_regain_access":3}]}',
            'x-ad-account-usage' => '{"acc_id_util_pct":9.5,"reset_time_duration":120}',
        ])]);
        $client = new MetaClient(TOKEN_FALSO, null, 'v26.0');
        $client->get('me');
        expect($client->usagePercent())->toBe(42.0);
        expect($client->minutesToRegainAccess())->toBe(3);
        expect($client->calls())->toBe(1);
    });
});

describe('Errores de Meta (§54)', function () {
    it('clasifica cada error', function (?int $code, ?int $subcode, string $message, int $status, string $kind) {
        expect(MetaApiException::classify($code, $subcode, $message, $status))->toBe($kind);
    })->with([
        'token inválido' => [190, 467, 'Invalid OAuth access token', 401, MetaApiException::TOKEN],
        'token vencido' => [190, 463, 'Session has expired', 401, MetaApiException::TOKEN],
        'sin permiso' => [10, null, 'Application does not have permission for this action', 403, MetaApiException::PERMISSION],
        'permiso de cuenta' => [200, null, 'Permissions error', 403, MetaApiException::PERMISSION],
        'campo con permiso' => [100, null, '(#100) Requires business_management permission to access the field.', 400, MetaApiException::PERMISSION],
        'objeto eliminado' => [100, 33, 'Unsupported get request. Object with ID does not exist', 400, MetaApiException::NOT_FOUND],
        'campo no disponible' => [100, null, '(#100) foo is not valid for fields param', 400, MetaApiException::FIELD],
        'límite de la app' => [4, null, 'Application request limit reached', 400, MetaApiException::RATE_LIMIT],
        'límite de la cuenta' => [17, null, 'User request limit reached', 400, MetaApiException::RATE_LIMIT],
        'límite de anuncios' => [80004, null, 'There have been too many calls to this ad-account', 400, MetaApiException::RATE_LIMIT],
        'versión vieja' => [2635, null, 'You are calling a deprecated version of the Ads API', 400, MetaApiException::VERSION],
        'demasiados datos' => [1, 99, 'Please reduce the amount of data you\'re asking for, then retry your request', 500, MetaApiException::TOO_MUCH_DATA],
        'error temporal' => [2, null, 'An unexpected error has occurred. Please retry your request later.', 500, MetaApiException::META],
        'sin código' => [null, null, 'HTTP 502', 502, MetaApiException::META],
    ]);

    it('distingue lo temporal de lo que afecta a la conexión', function () {
        expect((new MetaApiException(MetaApiException::RATE_LIMIT, 'x'))->isTransient())->toBeTrue();
        expect((new MetaApiException(MetaApiException::TOKEN, 'x'))->isTransient())->toBeFalse();
        expect((new MetaApiException(MetaApiException::TOKEN, 'x'))->affectsConnection())->toBeTrue();
        expect((new MetaApiException(MetaApiException::PERMISSION, 'x'))->affectsConnection())->toBeFalse();
    });
});

describe('Conexión de Meta', function () {
    it('no muestra el token ni el App Secret al convertirse en JSON (§4)', function () {
        $c = new MetaConnection(['name' => 'BM1', 'access_token' => TOKEN_FALSO, 'app_secret' => 'secreto']);
        $json = json_encode($c);
        expect($json)->not->toContain(TOKEN_FALSO)->not->toContain('secreto')->not->toContain('access_token');
        expect($c->access_token)->toBe(TOKEN_FALSO);
        // En la base queda cifrado
        expect($c->getAttributes()['access_token'])->not->toContain(TOKEN_FALSO);
    });
});
