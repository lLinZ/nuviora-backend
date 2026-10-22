<?php

namespace App\Services\Logistics;

use App\Models\RiderProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * La alarma al teléfono del rider (logística propia, §8.1, §25; audio del 21/10: "un tono fuerte"). Firebase Cloud
 * Messaging, API HTTP v1:
 * - POST https://fcm.googleapis.com/v1/projects/{proyecto}/messages:send, con un token OAuth de la cuenta de servicio
 *   (alcance firebase.messaging) que se firma aquí con OpenSSL y se guarda 50 minutos;
 * - mensaje solo de datos, prioridad alta en Android y vida corta (la de la ronda): la app arma la pantalla de alarma;
 * - al teléfono se le apunta por "fid" (Firebase Installation ID, el recomendado) o por "token" (el viejo).
 * El mensaje nunca lleva el destino, el cliente, el monto ni el producto (§8.1, §30): solo qué ronda es y el centro.
 * Sin configurar, no se manda nada y se dice por qué.
 */
class PushSender
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function configured(): bool
    {
        $path = (string) config('services.fcm.credentials');

        return (string) config('services.fcm.project_id') !== '' && $path !== '' && is_readable($path);
    }

    /**
     * @param  array<string, scalar>  $data
     * @return array{ok: bool, error: ?string, unregistered: bool, id: ?string}
     */
    public function send(RiderProfile $profile, array $data, int $ttlSeconds): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'La alarma no está configurada en el servidor (Firebase).', 'unregistered' => false, 'id' => null];
        }
        if (!$profile->hasPushTarget()) {
            return ['ok' => false, 'error' => 'El rider no tiene la app registrada.', 'unregistered' => false, 'id' => null];
        }

        try {
            $response = Http::timeout(10)->withToken($this->accessToken())
                ->post('https://fcm.googleapis.com/v1/projects/' . config('services.fcm.project_id') . '/messages:send',
                    self::body($profile, $data, $ttlSeconds));
        } catch (ConnectionException) {
            return ['ok' => false, 'error' => 'Firebase no respondió.', 'unregistered' => false, 'id' => null];
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'unregistered' => false, 'id' => null];
        }

        if ($response->successful()) {
            if ($profile->push_error) {
                $profile->forceFill(['push_error' => null])->save();
            }

            return ['ok' => true, 'error' => null, 'unregistered' => false, 'id' => $response->json('name')];
        }

        $code = (string) ($response->json('error.details.0.errorCode') ?? $response->json('error.status') ?? '');
        $unregistered = $code === 'UNREGISTERED' || $response->status() === 404;
        $error = $unregistered
            ? 'El teléfono ya no está registrado (la app se desinstaló o cambió): hay que volver a abrirla.'
            : 'Firebase respondió ' . $response->status() . ($code ? " ({$code})" : '');
        // Un teléfono que ya no existe no vuelve a recibir rondas hasta que la app se registre otra vez (§6.1)
        $profile->forceFill(['push_error' => mb_substr($error, 0, 250)]
            + ($unregistered ? ['push_target' => null, 'push_target_type' => null] : []))->save();

        return ['ok' => false, 'error' => $error, 'unregistered' => $unregistered, 'id' => null];
    }

    public static function body(RiderProfile $profile, array $data, int $ttlSeconds): array
    {
        return ['message' => [
            ($profile->push_target_type === 'token' ? 'token' : 'fid') => $profile->push_target,
            // FCM solo acepta textos en "data"
            'data' => array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : (string) $v, $data),
            'android' => ['priority' => 'high', 'ttl' => max(1, $ttlSeconds) . 's'],
        ]];
    }

    private function accessToken(): string
    {
        return Cache::remember('logistics:fcm-token', now()->addMinutes(50), function () {
            $account = json_decode((string) file_get_contents((string) config('services.fcm.credentials')), true);
            if (!is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
                throw new \RuntimeException('El archivo de la cuenta de servicio de Firebase no es válido.');
            }
            $tokenUri = $account['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();
            $segments = [
                self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::base64url(json_encode(['iss' => $account['client_email'], 'scope' => self::SCOPE, 'aud' => $tokenUri, 'iat' => $now, 'exp' => $now + 3600])),
            ];
            if (!openssl_sign(implode('.', $segments), $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('No se pudo firmar el pedido a Google con la cuenta de servicio de Firebase.');
            }
            $segments[] = self::base64url($signature);

            $response = Http::asForm()->timeout(10)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ]);
            if (!$response->successful() || !$response->json('access_token')) {
                throw new \RuntimeException('Google no dio el permiso para mandar la alarma (' . $response->status() . ').');
            }

            return (string) $response->json('access_token');
        });
    }

    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
