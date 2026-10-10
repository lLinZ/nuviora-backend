<?php

namespace App\Jobs;

use App\Models\PaymentReceipt;
use App\Models\ReceiptCheck;
use App\Services\Payments\ReceiptChecker;
use App\Services\Payments\ReceiptFingerprint;
use App\Services\Payments\ReceiptReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lee un comprobante con IA y lo compara con la orden (Fran, 2026-10-03). Se encola al subirlo, para que la
 * vendedora o la agencia no esperen. Si no cuadra el monto o el destino, encola una segunda lectura dígito por
 * dígito (2026-10-04) y el comprobante sigue "revisando" hasta que termine. Si el modelo principal no lo lee con
 * seguridad, encola otra lectura completa con el de respaldo (Fran, 2026-10-09: Luna → Sol → revisión manual). Cada
 * lectura va en su propio trabajo, para no pasar el tiempo límite.
 */
class AnalyzePaymentReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $timeout = 75; // menos que retry_after (90) de la cola, para que no se relance mientras corre

    /** Segunda lectura (solo monto y destino). Con valor por defecto: los trabajos ya encolados no lo traen. */
    public bool $reread = false;

    /** Lectura completa con el modelo de respaldo. */
    public bool $escalate = false;

    public function __construct(public int $receiptId, bool $reread = false, bool $escalate = false)
    {
        $this->reread = $reread;
        $this->escalate = $escalate;
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
        // Las huellas del archivo, para saber si el mismo comprobante está en otra orden (Fran, 2026-10-09)
        app(ReceiptFingerprint::class)->ensure($receipt);

        if ($this->reread) {
            // Con el mismo modelo que hizo la lectura que vale (el de respaldo, si ya se escaló)
            $checker->recordReread($check, $reader->reread($receipt, isset($check->extracted['escalado']) ? $check->model : null));
            $this->next($check->refresh(), $checker, false);
            return;
        }
        if ($this->escalate) {
            $checker->recordEscalation($check, $reader->read($receipt, ReceiptReader::fallbackModel()));
        } else {
            $checker->record($check, $reader->read($receipt));
        }
        $this->next($check->refresh(), $checker, true);
    }

    /** Lo que sigue después de una lectura: el modelo de respaldo si no se leyó con seguridad, o la segunda lectura. */
    private function next(ReceiptCheck $check, ReceiptChecker $checker, bool $canReread): void
    {
        $then = match (true) {
            $checker->needsEscalation($check) => [false, true],
            $canReread && $checker->needsReread($check) => [true, false],
            default => null,
        };
        if ($then) {
            // Sin el resultado de esta lectura a la vista: puede ser un error que la siguiente corrige
            $check->forceFill(['status' => ReceiptCheck::PENDING, 'issues' => []])->save();
            self::dispatch($this->receiptId, ...$then);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Revisión ' . ($this->reread ? '(segunda lectura) ' : ($this->escalate ? '(modelo de respaldo) ' : '')) . "del comprobante {$this->receiptId} falló: " . $e->getMessage());
        if ($this->reread || $this->escalate) {
            // Sin la segunda lectura (o sin el modelo de respaldo) vale lo de la primera
            $check = ReceiptCheck::with('order')->where('payment_receipt_id', $this->receiptId)->first();
            if ($check?->order) {
                app(ReceiptChecker::class)->evaluateOrder($check->order);
            }
            return;
        }
        ReceiptCheck::where('payment_receipt_id', $this->receiptId)->update([
            'status' => ReceiptCheck::ERROR,
            'error' => mb_strimwidth($e->getMessage(), 0, 500),
        ]);
    }
}
