<?php

namespace App\Services\Meta;

use App\Models\MetaAdAccount;
use App\Models\MetaConnection;
use App\Models\MetaSyncLog;
use Illuminate\Support\Carbon;

/**
 * Una sincronización de una conexión (documento de Fran del 2026-10-08, Módulo 2, §7, §8, §25, §52, §54):
 * - se arma una lista de pasos chicos (la jerarquía de una cuenta, un nivel de un rango de días, un rango fijo…);
 * - cada trabajo de la cola corre los pasos que le caben en su tiempo y deja el resto para el siguiente, así ninguno
 *   pasa el límite de la cola (retry_after) y nada se repite;
 * - si Meta falla, se registra el error y se conserva lo guardado (§7: "Un error temporal de Meta no debe borrar
 *   información histórica"); un token inválido detiene la conexión, un límite de llamadas deja el resto para la
 *   siguiente vuelta, y el error de una cuenta no frena a las demás (§54).
 */
class MetaSyncRunner
{
    public function __construct(
        private MetaConnectionService $connections,
        private MetaHierarchySync $hierarchy,
        private MetaInsightsSync $insights,
    ) {
    }

    /**
     * @param string $kind scheduled (cada 30 min), manual ("Sincronizar ahora", §56) o recheck (volver a pedir días
     *                     recientes, §25)
     */
    public function start(MetaConnection $connection, string $kind, ?int $userId = null, array $options = []): MetaSyncLog
    {
        return MetaSyncLog::create([
            'meta_connection_id' => $connection->id,
            'kind' => $kind,
            'requested_by' => $userId,
            'started_at' => now(),
            'status' => 'running',
            'details' => ['options' => $options, 'accounts' => [], 'errors' => []],
        ]);
    }

    /**
     * Corre pasos hasta usar el tiempo disponible.
     *
     * @param array|null $steps los pasos que quedaron del trabajo anterior; null en el primero
     * @return array los pasos que faltan ([] si terminó)
     */
    public function run(MetaSyncLog $log, ?array $steps, int $budgetSeconds = 50): array
    {
        $started = microtime(true);
        $connection = $log->connection;
        $client = $this->client = MetaClient::for($connection);
        $details = $log->details ?? [];

        try {
            if ($steps === null) {
                $this->connections->discoverAccounts($connection, $client);
                $steps = $this->plan($log, $connection);
                $details['steps_total'] = count($steps);
            }

            $ran = 0;
            while ($steps) {
                // Cada parte hace al menos un paso, así siempre avanza
                if ($ran > 0 && microtime(true) - $started > $budgetSeconds) {
                    break;
                }
                $ran++;
                $limit = (float) config('services.meta.usage_stop_percent', 75);
                if ($client->usagePercent() >= $limit) {
                    throw new MetaApiException(MetaApiException::RATE_LIMIT,
                        sprintf('Se frenó al %.0f %% del límite de Meta; sigue en la próxima vuelta.', $client->usagePercent()));
                }

                $step = array_shift($steps);
                if ($step['type'] === 'account_done') {
                    $this->accountDone($step['account'], $details);
                    continue;
                }
                try {
                    $log->records += $this->runStep($step);
                } catch (MetaApiException $e) {
                    if ($e->affectsConnection() || $e->kind === MetaApiException::RATE_LIMIT) {
                        throw $e;
                    }
                    $accountId = $step['account'] ?? null;
                    $details['errors'][] = ['account' => $accountId, 'step' => $step['type'], 'kind' => $e->kind, 'message' => $e->getMessage()];
                    if ($accountId && in_array($e->kind, [MetaApiException::PERMISSION, MetaApiException::NOT_FOUND], true)) {
                        MetaAdAccount::whereKey($accountId)->update([
                            'last_error_kind' => $e->kind, 'last_error' => $e->getMessage(), 'last_error_at' => now(),
                        ]);
                        $steps = array_values(array_filter($steps, fn ($s) => ($s['account'] ?? null) !== $accountId));
                    }
                }
            }
        } catch (MetaApiException $e) {
            $steps = [];
            $details['errors'][] = ['account' => null, 'step' => 'connection', 'kind' => $e->kind, 'message' => $e->getMessage()];
            $this->finish($log, $client, $details, $e);
            return [];
        } catch (\Throwable $e) {
            $details['errors'][] = ['account' => null, 'step' => 'connection', 'kind' => MetaApiException::META, 'message' => mb_substr($e->getMessage(), 0, 500)];
            $this->finish($log, $client, $details, new MetaApiException(MetaApiException::META, mb_substr($e->getMessage(), 0, 500)));
            throw $e;
        }

        $details['dropped_fields'] = array_values(array_unique(array_merge($details['dropped_fields'] ?? [], $this->insights->dropped())));
        if ($steps) {
            $log->calls += $client->calls();
            $log->details = $details;
            $log->save();
            return $steps;
        }
        $this->finish($log, $client, $details, null);
        return [];
    }

    /** Los pasos de una sincronización, cuenta por cuenta. */
    public function plan(MetaSyncLog $log, MetaConnection $connection): array
    {
        $options = $log->details['options'] ?? [];
        $accounts = $connection->adAccounts()->where('is_active', true)
            ->when(!empty($options['account']), fn ($q) => $q->whereKey($options['account']))
            ->orderBy('id')->get();
        $chunk = max(1, (int) config('services.meta.history_chunk_days', 7));
        $steps = [];

        foreach ($accounts as $account) {
            $today = $this->today($account);
            $a = $account->id;
            if (!$account->backfill_from) {
                // §8: "importar mínimo los últimos 30 días" la primera vez
                $account->forceFill(['backfill_from' => $today->copy()->subDays((int) config('services.meta.initial_days', 30) - 1)->toDateString()])->save();
            }

            if ($log->kind === 'recheck') {
                // §25: volver a comprobar los días recientes, porque Meta corrige las compras atribuidas después
                $days = max(1, (int) ($options['days'] ?? 7));
                $steps = array_merge($steps, $this->dailySteps($a, $today->copy()->subDays($days), $today->copy()->subDay(), $chunk));
                $steps[] = ['type' => 'account_done', 'account' => $a];
                continue;
            }

            $steps[] = ['type' => 'hierarchy', 'account' => $a];
            // §25: el día actual en cada vuelta, y el anterior, que todavía cambia
            $steps = array_merge($steps, $this->dailySteps($a, $today->copy()->subDay(), $today, 2));

            // §8: la importación inicial (30 días) o la ampliación del histórico
            $target = Carbon::parse($account->backfill_from->toDateString(), $today->getTimezone());
            $end = $account->history_from
                ? Carbon::parse($account->history_from->toDateString(), $today->getTimezone())->subDay()
                : $today->copy()->subDays(2);
            if ($end->gte($target)) {
                for ($until = $end->copy(); $until->gte($target); $until->subDays($chunk)) {
                    $since = $until->copy()->subDays($chunk - 1)->max($target);
                    foreach (MetaInsightsSync::LEVELS as $level) {
                        $steps[] = ['type' => 'daily', 'account' => $a, 'level' => $level, 'since' => $since->toDateString(), 'until' => $until->toDateString()];
                    }
                    $steps[] = ['type' => 'history_done', 'account' => $a, 'from' => $since->toDateString()];
                }
            }

            // §57: los rangos fijos, con el alcance y la frecuencia de Meta, cada cierto tiempo
            $every = (int) config('services.meta.presets_every_minutes', 60);
            if ($log->kind === 'manual' || !$account->presets_synced_at || $account->presets_synced_at->lt(now()->subMinutes($every))) {
                foreach ((array) config('services.meta.presets', MetaInsightsSync::PRESETS) as $preset) {
                    foreach (MetaInsightsSync::LEVELS as $level) {
                        $steps[] = ['type' => 'preset', 'account' => $a, 'level' => $level, 'preset' => $preset];
                    }
                }
                $steps[] = ['type' => 'presets_done', 'account' => $a];
            }
            $steps[] = ['type' => 'account_done', 'account' => $a];
        }

        return $steps;
    }

    private function dailySteps(int $account, Carbon $from, Carbon $to, int $chunk): array
    {
        $steps = [];
        for ($until = $to->copy(); $until->gte($from); $until->subDays($chunk)) {
            $since = $until->copy()->subDays($chunk - 1)->max($from);
            foreach (MetaInsightsSync::LEVELS as $level) {
                $steps[] = ['type' => 'daily', 'account' => $account, 'level' => $level, 'since' => $since->toDateString(), 'until' => $until->toDateString()];
            }
        }
        return $steps;
    }

    private function runStep(array $step): int
    {
        $account = MetaAdAccount::findOrFail($step['account']);
        $client = $this->client;

        switch ($step['type']) {
            case 'hierarchy':
                return $this->hierarchy->sync($account, $client);
            case 'daily':
                return $this->insights->daily($account, $client, $step['level'], $step['since'], $step['until']);
            case 'preset':
                return $this->insights->preset($account, $client, $step['level'], $step['preset']);
            case 'history_done':
                if (!$account->history_from || $account->history_from->toDateString() > $step['from']) {
                    $account->forceFill(['history_from' => $step['from']])->save();
                }
                return 0;
            case 'presets_done':
                $account->forceFill(['presets_synced_at' => now()])->save();
                return 0;
        }
        return 0;
    }

    /** La cuenta terminó su vuelta: se anota la sincronización y, si no tuvo errores, se limpia el último (§6). */
    private function accountDone(int $accountId, array $details): void
    {
        $account = MetaAdAccount::find($accountId);
        if (!$account) {
            return;
        }
        $errors = array_values(array_filter($details['errors'] ?? [], fn ($e) => ($e['account'] ?? null) === $accountId));
        $data = ['last_synced_at' => now(), 'first_synced_at' => $account->first_synced_at ?? now()];
        if ($errors) {
            $last = end($errors);
            $data += ['last_error_kind' => $last['kind'], 'last_error' => $last['message'], 'last_error_at' => now()];
        } else {
            $data += ['last_error_kind' => null, 'last_error' => null, 'last_error_at' => null];
        }
        $account->forceFill($data)->save();
    }

    private ?MetaClient $client = null;

    private function finish(MetaSyncLog $log, MetaClient $client, array $details, ?MetaApiException $error): void
    {
        $connection = $log->connection;
        $accountErrors = array_filter($details['errors'] ?? [], fn ($e) => $e['account'] !== null);

        $log->calls += $client->calls();
        $log->finished_at = now();
        $log->details = $details;
        if ($error) {
            $log->status = $error->kind === MetaApiException::RATE_LIMIT ? 'partial' : 'error';
            $log->error_kind = $error->kind;
            $log->error = $error->getMessage();
        } else {
            $log->status = $accountErrors ? 'partial' : 'ok';
        }
        $log->save();

        if ($error && $error->affectsConnection()) {
            $connection->forceFill(['status' => 'error', 'last_error_kind' => $error->kind, 'last_error' => $error->getMessage(), 'last_error_at' => now()])->save();
        } elseif ($error) {
            $connection->forceFill(['last_error_kind' => $error->kind, 'last_error' => $error->getMessage(), 'last_error_at' => now()])->save();
        } else {
            $connection->forceFill(['status' => 'ok', 'last_success_at' => now(), 'last_error_kind' => null, 'last_error' => null])->save();
        }
    }

    /** "Hoy" en la zona horaria de la cuenta (§6: se guarda desde Meta). */
    private function today(MetaAdAccount $account): Carbon
    {
        return now($account->timezone_name ?: config('app.timezone'))->startOfDay();
    }
}
