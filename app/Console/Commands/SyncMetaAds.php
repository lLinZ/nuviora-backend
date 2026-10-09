<?php

namespace App\Console\Commands;

use App\Jobs\SyncMetaConnection;
use App\Models\MetaConnection;
use App\Models\MetaSyncLog;
use Illuminate\Console\Command;

/**
 * Sincroniza las conexiones de Meta Ads (documento de Fran del 2026-10-08, Módulo 2):
 *   php artisan meta:sync                cada 30 minutos: hoy, ayer, la jerarquía y lo que falte del histórico (§7, §8)
 *   php artisan meta:sync --recheck=7    vuelve a pedir los últimos 7 días, porque Meta corrige las compras (§25)
 *   php artisan meta:sync --recheck=30   la revisión periódica del histórico reciente (§25)
 *   php artisan meta:sync --connection=1 --now   una sola conexión, sin la cola (para probar)
 */
class SyncMetaAds extends Command
{
    protected $signature = 'meta:sync {--recheck= : días hacia atrás que se vuelven a pedir} {--connection= : solo esta conexión} {--now : sin la cola}';

    protected $description = 'Sincroniza las cuentas de Meta Ads (solo lectura)';

    public function handle(): int
    {
        // Una sincronización que quedó "corriendo" más de 20 minutos se cortó (por ejemplo, un reinicio del servidor).
        MetaSyncLog::where('status', 'running')->where('started_at', '<', now()->subMinutes(20))->update([
            'status' => 'error', 'error_kind' => 'meta', 'error' => 'La sincronización se interrumpió.', 'finished_at' => now(),
        ]);

        $kind = $this->option('recheck') ? 'recheck' : 'scheduled';
        $options = $this->option('recheck') ? ['days' => max(1, (int) $this->option('recheck'))] : [];

        $connections = MetaConnection::query()
            ->when($this->option('connection'), fn ($q) => $q->whereKey($this->option('connection')))
            ->orderBy('id')->get();

        foreach ($connections as $connection) {
            if ($this->option('now')) {
                SyncMetaConnection::dispatchSync($connection->id, $kind, null, $options);
                $log = $connection->syncLogs()->latest('id')->first();
                $this->info("{$connection->name}: {$log?->status} · {$log?->records} registros · {$log?->calls} llamadas" . ($log?->error ? " · {$log->error}" : ''));
            } else {
                SyncMetaConnection::dispatch($connection->id, $kind, null, $options);
                $this->info("{$connection->name}: en la cola");
            }
        }

        return self::SUCCESS;
    }
}
