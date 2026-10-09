<?php

namespace App\Console\Commands;

use App\Jobs\SyncMetaConnection;
use App\Models\MetaAdAccount;
use Illuminate\Console\Command;

/**
 * Amplía el histórico de una cuenta de Meta (documento de Fran del 2026-10-08, Módulo 2, §8: "Debe existir la
 * posibilidad técnica de ampliar posteriormente este histórico"). Se importa en las siguientes sincronizaciones, por
 * partes, sin duplicar lo que ya está.
 *   php artisan meta:backfill 467604046276104 --days=90
 */
class BackfillMetaAds extends Command
{
    protected $signature = 'meta:backfill {account : ID de la cuenta en Meta (con o sin act_)} {--days=90 : cuántos días hacia atrás desde hoy}';

    protected $description = 'Amplía el histórico de una cuenta de Meta Ads';

    public function handle(): int
    {
        $metaId = preg_replace('/^act_/', '', (string) $this->argument('account'));
        $account = MetaAdAccount::where('meta_id', $metaId)->first();
        if (!$account) {
            $this->error("No hay ninguna cuenta {$metaId}.");
            return self::FAILURE;
        }

        $from = now($account->timezone_name ?: config('app.timezone'))->startOfDay()->subDays(max(1, (int) $this->option('days')) - 1)->toDateString();
        if ($account->backfill_from && $account->backfill_from->toDateString() <= $from) {
            $this->info("La cuenta ya pide el histórico desde el {$account->backfill_from->toDateString()}.");
            return self::SUCCESS;
        }
        $account->forceFill(['backfill_from' => $from])->save();
        if ($account->is_active) {
            SyncMetaConnection::dispatch($account->meta_connection_id, 'scheduled', null, ['account' => $account->id]);
        }
        $this->info("{$account->name}: el histórico se completará desde el {$from}" . ($account->is_active ? '.' : ' cuando se active la cuenta.'));

        return self::SUCCESS;
    }
}
