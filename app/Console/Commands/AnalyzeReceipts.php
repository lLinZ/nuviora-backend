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
 *   php artisan receipts:analyze --days=7 --reread   solo la segunda lectura (dígito por dígito) de los que no
 *                                                    cuadraron por monto o destino y no se releyeron
 */
class AnalyzeReceipts extends Command
{
    protected $signature = 'receipts:analyze {--days=7} {--order=} {--limit=0} {--again} {--reread}';

    protected $description = 'Lee con IA los comprobantes de pago y los compara con sus órdenes';

    private int $tokensIn = 0;
    private int $tokensOut = 0;

    public function handle(ReceiptReader $reader, ReceiptChecker $checker): int
    {
        if (!config('services.openai.key')) {
            $this->error('Falta OPENAI_API_KEY en el .env.');
            return self::FAILURE;
        }
        if ($this->option('reread')) {
            return $this->rereadFlagged($reader, $checker);
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

        $bar = $this->output->createProgressBar($receipts->count());
        foreach ($receipts as $receipt) {
            $check = ReceiptCheck::updateOrCreate(['payment_receipt_id' => $receipt->id], ['order_id' => $receipt->order_id, 'status' => ReceiptCheck::PENDING]);
            try {
                $result = $reader->read($receipt);
                $checker->record($check, $result);
                $this->addTokens($result);
            } catch (Throwable $e) {
                $check->forceFill(['status' => ReceiptCheck::ERROR, 'error' => mb_strimwidth($e->getMessage(), 0, 500)])->save();
                $bar->advance();
                continue;
            }
            if ($checker->needsReread($check->refresh())) {
                $this->reread($check, $receipt, $reader, $checker);
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $this->printSummary(ReceiptCheck::whereIn('payment_receipt_id', $receipts->pluck('id'))->get());

        return self::SUCCESS;
    }

    /** Segunda lectura de los ya revisados que no cuadraron por monto o destino (p. ej. los de antes del 4-oct). */
    private function rereadFlagged(ReceiptReader $reader, ReceiptChecker $checker): int
    {
        $query = ReceiptCheck::with(['receipt', 'order'])->whereIn('status', [ReceiptCheck::FAIL, ReceiptCheck::WARNING])->orderBy('id');
        if ($this->option('order')) {
            $order = $this->option('order');
            $query->whereHas('order', fn ($q) => $q->where(fn ($w) => $w->where('id', $order)->orWhere('name', 'like', '%' . $order)));
        } else {
            $query->whereHas('receipt', fn ($q) => $q->where('created_at', '>=', now()->subDays((int) $this->option('days'))));
        }
        $checks = $query->get()->filter(fn ($c) => $c->receipt && $c->order && $checker->needsReread($c))->values();
        if ((int) $this->option('limit') > 0) {
            $checks = $checks->take((int) $this->option('limit'));
        }
        $this->info("Comprobantes a releer: {$checks->count()}");

        $before = $checks->mapWithKeys(fn ($c) => [$c->id => $c->status]);
        $bar = $this->output->createProgressBar($checks->count());
        foreach ($checks as $check) {
            $this->reread($check, $check->receipt, $reader, $checker);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $after = ReceiptCheck::with('order:id,name')->whereIn('id', $checks->pluck('id'))->get();
        foreach ($after as $c) {
            if ($c->status !== $before[$c->id]) {
                $this->line("  {$c->order?->name}: {$before[$c->id]} → {$c->status}" . ($c->issues ? ' (' . collect($c->issues)->pluck('text')->implode(' | ') . ')' : ''));
            }
        }
        $this->printSummary($after);

        return self::SUCCESS;
    }

    /** Si la segunda lectura falla (IA caída), queda lo de la primera. */
    private function reread(ReceiptCheck $check, PaymentReceipt $receipt, ReceiptReader $reader, ReceiptChecker $checker): void
    {
        try {
            $result = $reader->reread($receipt);
            $checker->recordReread($check, $result);
            $this->addTokens($result);
        } catch (Throwable $e) {
            $this->warn("  Segunda lectura de {$check->order?->name} falló: " . mb_strimwidth($e->getMessage(), 0, 200));
        }
    }

    private function addTokens(array $result): void
    {
        $this->tokensIn += (int) ($result['input_tokens'] ?? 0);
        $this->tokensOut += (int) ($result['output_tokens'] ?? 0);
    }

    private function printSummary($checks): void
    {
        $this->table(['Resultado', 'Cuántos'], $checks->countBy('status')->map(fn ($n, $s) => [$s, $n])->values()->all());
        $this->table(['Tipo leído', 'Cuántos'], $checks->countBy(fn ($c) => $c->kind ?? '(sin leer)')->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->line("Tokens: {$this->tokensIn} de entrada y {$this->tokensOut} de salida.");

        foreach ($checks->whereIn('status', [ReceiptCheck::FAIL, ReceiptCheck::UNREADABLE, ReceiptCheck::ERROR]) as $c) {
            $this->line("  {$c->order?->name} [{$c->status}] " . ($c->error ?: collect($c->issues)->pluck('text')->implode(' | ')));
        }
    }
}
