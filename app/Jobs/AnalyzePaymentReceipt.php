<?php

namespace App\Jobs;

use App\Models\PaymentReceipt;
use App\Models\ReceiptCheck;
use App\Services\Payments\ReceiptChecker;
use App\Services\Payments\ReceiptReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lee un comprobante con IA y lo compara con la orden (Fran, 2026-10-03). Se encola al subirlo, para que la
 * vendedora o la agencia no esperen.
 */
class AnalyzePaymentReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $timeout = 75; // menos que retry_after (90) de la cola, para que no se relance mientras corre

    public function __construct(public int $receiptId)
    {
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    /** Crea la revisión "pendiente" y la encola. No hace nada si la revisión está apagada. */
    public static function forReceipt(PaymentReceipt $receipt): void
    {
        if (ReceiptChecker::mode() === 'off') {
            return;
        }
        ReceiptCheck::updateOrCreate(
            ['payment_receipt_id' => $receipt->id],
            ['order_id' => $receipt->order_id, 'status' => ReceiptCheck::PENDING, 'error' => null]
        );
        self::dispatch($receipt->id)->afterCommit();
    }

    public function handle(ReceiptReader $reader, ReceiptChecker $checker): void
    {
        $receipt = PaymentReceipt::with('order')->find($this->receiptId);
        if (!$receipt || !$receipt->order) {
            return; // lo borraron mientras esperaba
        }
        $check = ReceiptCheck::firstOrCreate(['payment_receipt_id' => $receipt->id], ['order_id' => $receipt->order_id]);

        $checker->record($check, $reader->read($receipt));
    }

    public function failed(Throwable $e): void
    {
        Log::warning("Revisión del comprobante {$this->receiptId} falló: " . $e->getMessage());
        ReceiptCheck::where('payment_receipt_id', $this->receiptId)->update([
            'status' => ReceiptCheck::ERROR,
            'error' => mb_strimwidth($e->getMessage(), 0, 500),
        ]);
    }
}
