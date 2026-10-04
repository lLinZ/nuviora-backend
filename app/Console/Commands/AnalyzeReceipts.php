<?php

namespace App\Console\Commands;

use App\Models\PaymentReceipt;
use App\Models\ReceiptCheck;
use App\Services\Payments\ReceiptChecker;
use App\Services\Payments\ReceiptReader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Revisa con IA comprobantes ya subidos (Fran, 2026-10-03). Sirve para medir qué tan bien lee antes de
 * bloquear entregas, y para revisar los que quedaron con error.
 *   php artisan receipts:analyze --days=7            los de la última semana que no tienen revisión
 *   php artisan receipts:analyze --order=26882       los de una orden
 *   php artisan receipts:analyze --days=7 --again    también los que ya tienen revisión
 */
class AnalyzeReceipts extends Command
{
    protected $signature = 'receipts:analyze {--days=7} {--order=} {--limit=0} {--again}';

    protected $description = 'Lee con IA los comprobantes de pago y los compara con sus órdenes';

    public function handle(ReceiptReader $reader, ReceiptChecker $checker): int
    {
        if (!config('services.openai.key')) {
            $this->error('Falta OPENAI_API_KEY en el .env.');
            return self::FAILURE;
        }

        $query = PaymentReceipt::with('order')->whereHas('order')->orderBy('id');
        if ($this->option('order')) {
            $order = $this->option('order');
            $query->whereHas('order', fn ($q) => $q->where(fn ($w) => $w->where('id', $order)->orWhere('name', 'like', '%' . $order)));
        } else {
            $query->where('created_at', '>=', now()->subDays((int) $this->option('days')));
        }
        if (!$this->option('again')) {
            $query->whereDoesntHave('check', fn ($q) => $q->whereNotIn('status', [ReceiptCheck::PENDING, ReceiptCheck::ERROR]));
        }
        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }
        $receipts = $query->get();
        $this->info("Comprobantes a revisar: {$receipts->count()}");

        $tokensIn = $tokensOut = 0;
        $bar = $this->output->createProgressBar($receipts->count());
        foreach ($receipts as $receipt) {
            $check = ReceiptCheck::updateOrCreate(['payment_receipt_id' => $receipt->id], ['order_id' => $receipt->order_id, 'status' => ReceiptCheck::PENDING]);
            try {
                $result = $reader->read($receipt);
                $checker->record($check, $result);
                $tokensIn += (int) ($result['input_tokens'] ?? 0);
                $tokensOut += (int) ($result['output_tokens'] ?? 0);
            } catch (Throwable $e) {
                $check->forceFill(['status' => ReceiptCheck::ERROR, 'error' => mb_strimwidth($e->getMessage(), 0, 500)])->save();
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $ids = $receipts->pluck('id');
        $checks = ReceiptCheck::whereIn('payment_receipt_id', $ids)->get();
        $this->table(['Resultado', 'Cuántos'], $checks->countBy('status')->map(fn ($n, $s) => [$s, $n])->values()->all());
        $this->table(['Tipo leído', 'Cuántos'], $checks->countBy(fn ($c) => $c->kind ?? '(sin leer)')->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->line("Tokens: {$tokensIn} de entrada y {$tokensOut} de salida.");

        foreach ($checks->whereIn('status', [ReceiptCheck::FAIL, ReceiptCheck::UNREADABLE, ReceiptCheck::ERROR]) as $c) {
            $this->line("  {$c->order?->name} [{$c->status}] " . ($c->error ?: collect($c->issues)->pluck('text')->implode(' | ')));
        }

        return self::SUCCESS;
    }
}
