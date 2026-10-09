<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMetaConnection;
use App\Models\MetaAdAccount;
use App\Models\MetaConnection;
use App\Models\MetaSyncLog;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Configuración → Meta Ads (documento de Fran del 2026-10-08, Módulo 2): conexiones (§3, §4, §55) y cuentas (§5, §6).
 * Solo el Administrador: las rutas llevan role:Admin (§44).
 *
 * Fran, en el audio del 2026-10-08: "así como las tiendas de Shopify… que yo ponga el token, ponga la ID… y vincule el
 * nuevo BM". El token entra por aquí una sola vez; nunca vuelve en ninguna respuesta (§4).
 */
class MetaConnectionController extends Controller
{
    public function __construct(private MetaConnectionService $service)
    {
    }

    public function index()
    {
        $connections = MetaConnection::with(['adAccounts' => fn ($q) => $q->orderBy('name')])->orderBy('id')->get();
        $owners = MetaAdAccount::with('connection:id,name')->get()->keyBy('meta_id');

        return response()->json([
            'graph_version' => config('services.meta.graph_version'),
            'connections' => $connections->map(fn (MetaConnection $c) => $this->present($c, $owners)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'business_id' => ['nullable', 'string', 'max:32', 'regex:/^\d+$/'],
            'access_token' => 'required|string|min:20|max:2000',
            'app_secret' => 'nullable|string|max:255',
        ]);
        $token = trim($data['access_token']);
        $secret = isset($data['app_secret']) ? trim($data['app_secret']) : null;

        $client = new MetaClient($token, $secret ?: null);
        try {
            $who = $this->service->verify($client);
        } catch (MetaApiException $e) {
            return $this->rejected($e);
        }

        $connection = MetaConnection::create([
            'name' => $data['name'],
            'business_id' => $data['business_id'] ?? null,
            'access_token' => $token,
            'app_secret' => $secret ?: null,
            'meta_user_id' => $who['user_id'],
            'meta_user_name' => $who['user_name'],
            'scopes' => $who['scopes'],
            'status' => 'pending',
            'created_by' => Auth::id(),
        ]);

        return $this->afterSave($connection, $client, 201);
    }

    /** Cambiar el nombre, el Business ID o la credencial. Si no se manda token, queda el guardado. */
    public function update(Request $request, MetaConnection $connection)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'business_id' => ['nullable', 'string', 'max:32', 'regex:/^\d+$/'],
            'access_token' => 'nullable|string|min:20|max:2000',
            'app_secret' => 'nullable|string|max:255',
            'clear_app_secret' => 'sometimes|boolean',
        ]);

        $token = filled($data['access_token'] ?? null) ? trim($data['access_token']) : null;
        $secret = filled($data['app_secret'] ?? null) ? trim($data['app_secret']) : null;
        $clearSecret = (bool) ($data['clear_app_secret'] ?? false);
        $credentialChanged = $token !== null || $secret !== null || $clearSecret;

        $client = null;
        if ($credentialChanged) {
            $client = new MetaClient($token ?? (string) $connection->access_token,
                $clearSecret ? null : ($secret ?? ($connection->app_secret ?: null)));
            try {
                $who = $this->service->verify($client);
            } catch (MetaApiException $e) {
                return $this->rejected($e);
            }
            $connection->fill([
                'meta_user_id' => $who['user_id'], 'meta_user_name' => $who['user_name'], 'scopes' => $who['scopes'],
                'last_error_kind' => null, 'last_error' => null, 'last_error_at' => null,
            ]);
            if ($token !== null) {
                $connection->access_token = $token;
            }
            if ($clearSecret) {
                $connection->app_secret = null;
            } elseif ($secret !== null) {
                $connection->app_secret = $secret;
            }
        }

        if (array_key_exists('name', $data)) {
            $connection->name = $data['name'];
        }
        if (array_key_exists('business_id', $data)) {
            $connection->business_id = $data['business_id'];
        }
        $connection->save();

        return $client ? $this->afterSave($connection, $client, 200) : $this->show($connection);
    }

    public function show(MetaConnection $connection)
    {
        $connection->load(['adAccounts' => fn ($q) => $q->orderBy('name')]);
        $owners = MetaAdAccount::with('connection:id,name')->get()->keyBy('meta_id');
        return response()->json(['connection' => $this->present($connection, $owners)]);
    }

    /** Vuelve a pedir a Meta la lista de cuentas de la conexión (§5: añadir cuentas sin el programador). */
    public function refreshAccounts(MetaConnection $connection)
    {
        try {
            $this->service->discoverAccounts($connection, MetaClient::for($connection));
        } catch (MetaApiException $e) {
            $connection->forceFill(['last_error_kind' => $e->kind, 'last_error' => $e->getMessage(), 'last_error_at' => now()])->save();
            return $this->rejected($e);
        }
        return $this->show($connection->refresh());
    }

    /**
     * Activar o desactivar una cuenta (§5). Desactivar = dejar de sincronizarla: no borra nada guardado ni toca Meta.
     */
    public function updateAccount(Request $request, MetaAdAccount $account)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        $active = (bool) $data['is_active'];

        if ($active && !$account->is_active) {
            $account->activated_at = now();
        }
        $account->is_active = $active;
        $account->save();

        if ($active) {
            // La importación inicial de 30 días (§8) empieza ya, sin esperar la siguiente vuelta
            SyncMetaConnection::dispatch($account->meta_connection_id, 'scheduled', Auth::id(), ['account' => $account->id]);
        }

        return $this->show($account->connection);
    }

    /**
     * "Sincronizar ahora" (§56). Si ya hay una en curso, no se lanza otra: el doble clic no crea decenas de
     * sincronizaciones simultáneas.
     */
    public function sync(MetaConnection $connection)
    {
        if ($this->running($connection)) {
            return response()->json(['status' => false, 'message' => 'Ya se está sincronizando esta conexión.'], 409);
        }
        SyncMetaConnection::dispatch($connection->id, 'manual', Auth::id());

        return response()->json(['status' => true, 'message' => 'Sincronización en marcha.'], 202);
    }

    private function running(MetaConnection $connection): bool
    {
        return $connection->syncLogs()->where('status', 'running')->where('started_at', '>=', now()->subMinutes(20))->exists();
    }

    /** Las últimas sincronizaciones de una conexión (§49, §55). */
    public function logs(MetaConnection $connection)
    {
        $logs = $connection->syncLogs()->with('requester:id,names')->latest('id')->limit(30)->get()
            ->map(fn (MetaSyncLog $l) => [
                'id' => $l->id, 'kind' => $l->kind, 'status' => $l->status,
                'started_at' => $l->started_at?->toIso8601String(), 'finished_at' => $l->finished_at?->toIso8601String(),
                'records' => $l->records, 'calls' => $l->calls,
                'error_kind' => $l->error_kind, 'error_label' => MetaApiException::labelFor($l->error_kind), 'error' => $l->error,
                'requested_by' => $l->requester?->names, 'details' => $l->details,
            ]);
        return response()->json(['logs' => $logs]);
    }

    private function afterSave(MetaConnection $connection, MetaClient $client, int $status)
    {
        try {
            $this->service->discoverAccounts($connection, $client);
        } catch (MetaApiException $e) {
            $connection->forceFill(['last_error_kind' => $e->kind, 'last_error' => $e->getMessage(), 'last_error_at' => now()])->save();
        }
        $connection->load(['adAccounts' => fn ($q) => $q->orderBy('name')]);
        $owners = MetaAdAccount::with('connection:id,name')->get()->keyBy('meta_id');
        return response()->json(['connection' => $this->present($connection, $owners)], $status);
    }

    private function rejected(MetaApiException $e)
    {
        return response()->json([
            'status' => false,
            'message' => 'Meta no aceptó la conexión: ' . $e->label() . '. ' . $e->getMessage(),
            'kind' => $e->kind,
        ], 422);
    }

    /** Lo que ve la pantalla. Nunca el token ni el App Secret, ni siquiera cortados (§4). */
    private function present(MetaConnection $c, $owners): array
    {
        $own = $c->adAccounts;
        $others = collect($c->available_accounts ?? [])
            ->filter(fn ($a) => ($owner = $owners->get($a['meta_id'] ?? '')) && $owner->meta_connection_id !== $c->id)
            ->map(fn ($a) => $a + ['synced_by' => $owners->get($a['meta_id'])->connection?->name])
            ->values();

        return [
            'id' => $c->id,
            'name' => $c->name,
            'business_id' => $c->business_id,
            'has_token' => filled($c->getRawOriginal('access_token')),
            'has_app_secret' => filled($c->getRawOriginal('app_secret')),
            'meta_user_id' => $c->meta_user_id,
            'meta_user_name' => $c->meta_user_name,
            'scopes' => $c->scopes,
            'extra_scopes' => MetaConnectionService::extraScopes($c->scopes),
            'status' => $c->status,
            'last_success_at' => $c->last_success_at?->toIso8601String(),
            'last_error_kind' => $c->last_error_kind,
            'last_error_label' => MetaApiException::labelFor($c->last_error_kind),
            'last_error' => $c->last_error,
            'last_error_at' => $c->last_error_at?->toIso8601String(),
            'accounts_checked_at' => $c->accounts_checked_at?->toIso8601String(),
            'syncing' => $this->running($c),
            'accounts' => $own->map(fn (MetaAdAccount $a) => [
                'id' => $a->id,
                'meta_id' => $a->meta_id,
                'name' => $a->name,
                'currency' => $a->currency,
                'timezone_name' => $a->timezone_name,
                'account_status' => $a->account_status,
                'is_active' => $a->is_active,
                'activated_at' => $a->activated_at?->toIso8601String(),
                'history_from' => $a->history_from?->toDateString(),
                'first_synced_at' => $a->first_synced_at?->toIso8601String(),
                'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                'last_error_kind' => $a->last_error_kind,
                'last_error_label' => MetaApiException::labelFor($a->last_error_kind),
                'last_error' => $a->last_error,
            ])->values(),
            'other_accounts' => $others,
            'active_accounts' => $own->where('is_active', true)->count(),
        ];
    }
}
