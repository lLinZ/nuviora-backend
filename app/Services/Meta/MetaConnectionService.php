<?php

namespace App\Services\Meta;

use App\Models\MetaAdAccount;
use App\Models\MetaConnection;

/**
 * Alta de conexiones y lista de sus cuentas (documento de Fran del 2026-10-08, Módulo 2, §3 a §6).
 */
class MetaConnectionService
{
    /** Permisos que bastan para leer; cualquier otro permite más de lo que el módulo necesita (§1). */
    public const READ_SCOPES = ['ads_read', 'read_insights', 'public_profile'];

    /**
     * Prueba un token antes de guardarlo: quién es (GET /me) y qué permisos tiene (GET /me/permissions). Sin ads_read
     * no puede leer las cuentas ni sus métricas, así que no se acepta.
     *
     * @return array{user_id: ?string, user_name: ?string, scopes: ?array}
     */
    public function verify(MetaClient $client): array
    {
        $me = $client->get('me', ['fields' => 'id,name']);

        $scopes = null;
        try {
            $rows = $client->get('me/permissions')['data'] ?? [];
            $scopes = array_values(array_map(fn ($p) => (string) $p['permission'],
                array_filter($rows, fn ($p) => ($p['status'] ?? '') === 'granted' && isset($p['permission']))));
        } catch (MetaApiException $e) {
            if ($e->kind === MetaApiException::TOKEN) {
                throw $e;
            }
            // Algunos tokens no pueden listar sus permisos: se sabrá al leer las cuentas.
        }

        if ($scopes !== null && !in_array('ads_read', $scopes, true)) {
            throw new MetaApiException(MetaApiException::PERMISSION,
                'El token no tiene el permiso ads_read. Tiene: ' . (implode(', ', $scopes) ?: 'ninguno') . '.');
        }

        return ['user_id' => $me['id'] ?? null, 'user_name' => $me['name'] ?? null, 'scopes' => $scopes];
    }

    /** Permisos de más para una integración de solo lectura (para avisar en la pantalla, §1). */
    public static function extraScopes(?array $scopes): array
    {
        return array_values(array_diff($scopes ?? [], self::READ_SCOPES));
    }

    /**
     * Las cuentas que ve la conexión (§3: "cuentas publicitarias disponibles"). Las nuevas quedan desactivadas hasta
     * que el Admin las active (§5). Una cuenta que ya se sincroniza con otra conexión no cambia de conexión. Si la
     * conexión deja de ver una de las suyas, no se borra: queda con el error.
     *
     * @return array{total: int, new: int}
     */
    public function discoverAccounts(MetaConnection $connection, MetaClient $client): array
    {
        $rows = $client->getAll('me/adaccounts', [
            'fields' => 'account_id,name,currency,timezone_name,account_status',
            'limit' => 100,
        ]);

        $now = now();
        $seen = [];
        $new = 0;
        foreach ($rows as $row) {
            $metaId = (string) ($row['account_id'] ?? preg_replace('/^act_/', '', (string) ($row['id'] ?? '')));
            if ($metaId === '') {
                continue;
            }
            $seen[] = $metaId;
            $data = [
                'name' => $row['name'] ?? null,
                'currency' => $row['currency'] ?? null,
                'timezone_name' => $row['timezone_name'] ?? null,
                'account_status' => isset($row['account_status']) ? (int) $row['account_status'] : null,
            ];

            $account = MetaAdAccount::where('meta_id', $metaId)->first();
            if (!$account) {
                MetaAdAccount::create($data + [
                    'meta_connection_id' => $connection->id, 'meta_id' => $metaId, 'is_active' => false, 'last_seen_at' => $now,
                ]);
                $new++;
                continue;
            }
            if ($account->meta_connection_id === $connection->id) {
                $data['last_seen_at'] = $now;
                if ($account->last_error_kind === MetaApiException::PERMISSION) {
                    $data += ['last_error_kind' => null, 'last_error' => null, 'last_error_at' => null];
                }
            }
            $account->update($data);
        }

        MetaAdAccount::where('meta_connection_id', $connection->id)->whereNotIn('meta_id', $seen ?: [''])
            ->where(fn ($q) => $q->whereNull('last_error_kind')->orWhere('last_error_kind', '!=', MetaApiException::PERMISSION))
            ->update([
                'last_error_kind' => MetaApiException::PERMISSION,
                'last_error' => 'La conexión ya no ve esta cuenta en Meta.',
                'last_error_at' => $now,
            ]);

        $connection->forceFill([
            'available_accounts' => array_map(fn ($r) => [
                'meta_id' => (string) ($r['account_id'] ?? ''),
                'name' => $r['name'] ?? null,
                'currency' => $r['currency'] ?? null,
                'account_status' => isset($r['account_status']) ? (int) $r['account_status'] : null,
            ], $rows),
            'accounts_checked_at' => $now,
        ])->save();

        return ['total' => count($rows), 'new' => $new];
    }
}
