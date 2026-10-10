<?php

namespace App\Console\Commands;

use App\Models\PaymentReceipt;
use App\Models\ReceiptCheck;
use App\Services\Payments\ReceiptChecker;
use App\Services\Payments\ReceiptFingerprint;
use Illuminate\Console\Command;

/**
 * Para después de desplegar la detección de duplicados por capas (Fran, 2026-10-09): calcula las huellas de los
 * comprobantes recientes y vuelve a revisar las órdenes que tenían un aviso de duplicado. No llama a la IA. Dice cuáles
 * dejaron de estar bloqueadas.
 */
class ReceiptsRecheckDuplicates extends Command
{
    protected $signature = 'receipts:recheck-duplicates {--days=30 : comprobantes de los últimos N días}';

    protected $description = 'Calcula las huellas de los comprobantes y vuelve a revisar los marcados como duplicados (sin IA)';

    public function handle(ReceiptFingerprint $fingerprint, ReceiptChecker $checker): int
    {
        $since = now()->subDays((int) $this->option('days'));
        $hashed = 0;
        PaymentReceipt::where('created_at', '>=', $since)->whereNull('file_sha256')->whereHas('check')->orderBy('id')
            ->chunkById(200, function ($receipts) use ($fingerprint, &$hashed) {
                foreach ($receipts as $r) {
                    $hashed += $fingerprint->ensure($r)->file_sha256 ? 1 : 0;
                }
            });
        $this->info("Huellas calculadas: {$hashed}.");

        $flagged = ReceiptCheck::where('created_at', '>=', $since)->whereNotNull('kind')->get()
            ->filter(fn (ReceiptCheck $c) => in_array('duplicate', array_column($c->issues ?? [], 'code'), true));
        $before = $flagged->mapWithKeys(fn ($c) => [$c->id => $c->status]);
        foreach ($flagged->pluck('order')->filter()->unique('id') as $order) {
            $checker->evaluateOrder($order);
        }
        foreach (ReceiptCheck::with('order:id,name')->whereIn('id', $before->keys())->get() as $c) {
            $still = in_array('duplicate', array_column($c->issues ?? [], 'code'), true);
            $this->line(sprintf('%s · comprobante %d: %s → %s%s', $c->order?->name ?? "#{$c->order_id}", $c->payment_receipt_id, $before[$c->id], $c->status,
                $still ? ' (sigue: ' . ($c->duplicate_reason ?? 'repetido dentro de la misma orden') . ')' : ' (ya no es duplicado)'));
        }
        $this->info("Revisados: {$before->count()}.");

        return self::SUCCESS;
    }
}
