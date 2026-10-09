<?php

namespace App\Jobs;

use App\Models\MetaConnection;
use App\Models\MetaSyncLog;
use App\Services\Meta\MetaSyncRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Sincroniza una conexión de Meta por la cola (documento de Fran del 2026-10-08, Módulo 2, §7: "mediante jobs/backend",
 * sin depender de que alguien tenga abierto el dashboard).
 *
 * Cada trabajo corre lo que le cabe en ~35 s y encola el resto, porque la cola relanza lo que pasa de 90 s
 * (retry_after). Una sola sincronización por conexión a la vez (§56): no se encola otra mientras haya una esperando, y
 * un candado impide que dos partes corran juntas.
 */
class SyncMetaConnection implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;      // si falla, la siguiente vuelta (30 min) lo vuelve a intentar (§7)
    public int $timeout = 85;   // menos que retry_after (90) de la cola
    public int $uniqueFor = 900;

    public function __construct(
        public int $connectionId,
        public string $kind = 'scheduled',
        public ?int $userId = null,
        public array $options = [],
        public ?int $logId = null,
        public ?array $steps = null,
    ) {
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(MetaSyncRunner $runner): void
    {
        $connection = MetaConnection::find($this->connectionId);
        if (!$connection) {
            return;
        }

        $lock = Cache::lock('meta-sync:' . $this->connectionId, 120);
        if (!$lock->get()) {
            if ($this->logId !== null) {
                // La parte anterior todavía no soltó el candado: esta sigue en un momento.
                self::dispatch($this->connectionId, $this->kind, $this->userId, $this->options, $this->logId, $this->steps)->delay(now()->addSeconds(20));
            }
            return; // una sincronización nueva mientras otra corre: la que corre ya trae los datos
        }

        $remaining = [];
        $log = null;
        try {
            $log = $this->logId ? MetaSyncLog::find($this->logId) : $runner->start($connection, $this->kind, $this->userId, $this->options);
            if (!$log || $log->status !== 'running') {
                return;
            }
            $remaining = $runner->run($log, $this->steps, (int) config('services.meta.job_budget_seconds', 35));
        } finally {
            $lock->release();
        }

        if ($remaining && $log) {
            self::dispatch($this->connectionId, $this->kind, $this->userId, $this->options, $log->id, $remaining)->delay(now()->addSeconds(2));
        }
    }
}
