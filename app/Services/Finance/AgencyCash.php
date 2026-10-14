<?php

namespace App\Services\Finance;

use App\Models\AccountPayable;
use App\Models\AgencyWeeklyClosure;
use App\Models\CashMovement;
use App\Models\DeliveryAuditLog;
use App\Models\FinancialAccount;
use App\Models\FinancialAuditLog;
use App\Models\PayablePayment;
use App\Services\Delivery\Closures\ClosureReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Las agencias en la caja (Módulo 4 de Fran, §43, §101 a §104, R16):
 * - §101 (caso 15): el cierre aprobado ("Listo para pago") es una deuda con la agencia por el monto final. Si el cierre se
 *   reabre, la deuda se anula (si todavía no tiene pagos).
 * - §102 (caso 16): el pago del cierre sale de una cuenta de la caja y lo deja "Pagado".
 * - §43 y R16: la agencia entrega el efectivo que cobró, el día que sea. Pasa de su "Efectivo pendiente" a la cuenta donde se
 *   guarda (no es ingreso nuevo). Si el Admin lo marca, una parte se descuenta de lo que se le debe (§103: nunca solo); si con
 *   eso el cierre queda pagado, pasa a "Pagado".
 */
class AgencyCash
{
    public const SOURCE_CLOSURE = 'agency_closure';
    public const COMPENSATION_METHOD = 'Descontado del efectivo';

    private const DOLLARS = ['USD', 'USDT'];

    public function __construct(private Payables $payables, private CashLedger $ledger)
    {
    }

    // ---------------------------------------------------------------------------------------------------------------
    // §101: la deuda del cierre

    public function syncClosure(AgencyWeeklyClosure $closure): void
    {
        $payable = AccountPayable::where('source_type', self::SOURCE_CLOSURE)->where('source_id', $closure->id)->first();
        $approved = in_array($closure->status, [AgencyWeeklyClosure::READY, AgencyWeeklyClosure::PAID], true) && $closure->final_approved_total !== null;
        if ($approved) {
            $total = round((float) $closure->final_approved_total, 2);
            $attributes = [
                'payee_type' => 'agency',
                'agency_id' => $closure->agency_id,
                'description' => 'Cierre del ' . $closure->period_start->format('d/m') . ' al ' . $closure->period_end->format('d/m/Y'),
                'currency' => 'USD',
                'amount' => $total,
                'rate' => 1,
                'amount_usd' => $total,
                'voided_at' => null,
                'void_reason' => null,
            ];
            if (!$payable) {
                $payable = AccountPayable::create($attributes + [
                    'source_type' => self::SOURCE_CLOSURE,
                    'source_id' => $closure->id,
                    'issued_at' => ($closure->approved_at ?? now())->toDateString(),
                    'created_by' => $closure->approved_by,
                ]);
                FinancialAuditLog::record('agency_payable_created', 'account_payable', $payable->id, null,
                    ['description' => $payable->description, 'amount' => $total], null, $closure->approved_by);
            } elseif (abs($payable->amount - $total) >= 0.005 || $payable->voided_at) {
                $before = ['amount' => $payable->amount, 'voided' => $payable->voided_at !== null];
                $payable->update($attributes);
                FinancialAuditLog::record('agency_payable_updated', 'account_payable', $payable->id, $before, ['amount' => $total, 'voided' => false]);
            }

            return;
        }
        if ($payable && !$payable->voided_at && $payable->paid_amount <= 0) {
            $payable->update(['voided_at' => now(), 'void_reason' => 'Se reabrió el cierre: volvió a tener casos por resolver.']);
            FinancialAuditLog::record('agency_payable_voided', 'account_payable', $payable->id, ['voided' => false], ['voided' => true], $payable->void_reason);
        }
    }

    public function closurePayable(AgencyWeeklyClosure $closure): ?AccountPayable
    {
        return AccountPayable::where('source_type', self::SOURCE_CLOSURE)->where('source_id', $closure->id)->whereNull('voided_at')->first();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // §102: el pago del cierre

    /**
     * Paga lo que queda del cierre desde una cuenta de la caja. En una cuenta en Bs se escribe el monto en Bs (la tasa sale
     * sola); en una en dólares, el pago es lo que queda. Lo que ya se descontó del efectivo (R16) no se paga dos veces.
     * @param array{date: string, financial_account_id: int, amount_ves?: float|string|null, method?: ?string, reference?: ?string} $data
     * @return float lo que se pagó, en USD
     */
    public function payClosure(AgencyWeeklyClosure $closure, array $data, ?string $receiptPath, int $userId): float
    {
        $this->syncClosure($closure);
        $payable = $this->closurePayable($closure) ?? throw new RuntimeException('El cierre todavía no está listo para pago.');
        $balance = $payable->balance();
        if ($balance <= 0.005) {
            return 0.0; // ya se descontó todo del efectivo
        }
        $account = !empty($data['financial_account_id']) ? FinancialAccount::find($data['financial_account_id']) : null;
        if (!$account) {
            if ($data['date'] > CashLedger::startDate()) {
                throw new RuntimeException('Elige la cuenta de donde sale el pago.');
            }
            // Pagado antes de que arrancara la caja: ya está en los saldos iniciales
            $this->payables->pay($payable, ['amount' => $balance, 'currency' => 'USD', 'rate' => 1, 'paid_at' => $data['date'],
                'receipt_path' => $receiptPath, 'notes' => 'Pago del cierre']);

            return $balance;
        }
        if ($account->isAgencyCash()) {
            throw new RuntimeException('Para descontarlo del efectivo de la agencia se usa "Liquidar efectivo" (Finanzas › Agencias).');
        }
        if ($account->currency === 'VES') {
            $ves = round((float) ($data['amount_ves'] ?? 0), 2);
            if ($ves <= 0) {
                throw new RuntimeException('Escribe cuántos bolívares se pagaron.');
            }
            $payment = ['amount' => $ves, 'currency' => 'VES', 'rate' => round($ves / $balance, 4)];
        } elseif (in_array($account->currency, self::DOLLARS, true)) {
            $payment = ['amount' => $balance, 'currency' => $account->currency, 'rate' => 1];
        } else {
            throw new RuntimeException("La cuenta {$account->name} no es en dólares ni en bolívares.");
        }
        $notes = trim(implode(' · ', array_filter([$data['method'] ?? null, isset($data['reference']) && $data['reference'] ? "ref. {$data['reference']}" : null])));
        $this->payables->pay($payable, $payment + [
            'paid_at' => $data['date'],
            'financial_account_id' => $account->id,
            'receipt_path' => $receiptPath,
            'notes' => 'Pago del cierre' . ($notes !== '' ? " ({$notes})" : ''),
        ]);

        return $balance;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // §43, §103 y R16: la liquidación del efectivo

    /**
     * @param array{occurred_on: string, delivered?: float|string|null, to_account_id?: ?int, compensation?: float|string|null,
     *              rate?: float|string|null, reference?: ?string, notes?: ?string} $data
     */
    public function settle(int $agencyId, string $currency, array $data, ?UploadedFile $receipt, int $userId): CashMovement
    {
        $from = $this->ledger->agencyCashAccount($agencyId, $currency);
        $date = $data['occurred_on'];
        $delivered = round((float) ($data['delivered'] ?? 0), 2);
        $compensation = round((float) ($data['compensation'] ?? 0), 2);
        $rate = $currency === 'VES' ? (float) ($data['rate'] ?? 0) : 1.0;
        if ($delivered < 0 || $compensation < 0 || $delivered + $compensation <= 0) {
            throw new RuntimeException('Escribe cuánto entregó la agencia o cuánto se descuenta de lo que se le debe.');
        }
        if ($currency === 'VES' && $rate <= 0) {
            throw new RuntimeException('Escribe la tasa (Bs por USD).');
        }
        if ($date <= $from->opening_date->toDateString()) {
            throw new RuntimeException('La fecha tiene que ser después del saldo inicial (' . $from->opening_date->format('d/m/Y') . ').');
        }
        $to = null;
        if ($delivered > 0) {
            $to = !empty($data['to_account_id']) ? FinancialAccount::find($data['to_account_id']) : null;
            if (!$to || !$to->is_active || $to->isAgencyCash()) {
                throw new RuntimeException('Elige la cuenta donde se guarda el efectivo que entregó.');
            }
            if ($this->family($to->currency) !== $this->family($currency)) {
                throw new RuntimeException("La cuenta {$to->name} es en {$to->currency}: el efectivo en " . ($currency === 'VES' ? 'bolívares' : 'dólares') . ' va a una cuenta de esa moneda.');
            }
            if ($date <= $to->opening_date->toDateString()) {
                throw new RuntimeException("La fecha tiene que ser después del saldo inicial de {$to->name}.");
            }
        }
        $usd = fn (float $amount) => round($currency === 'VES' ? $amount / $rate : $amount, 2);
        $payables = $this->openPayables($agencyId);
        $owed = round($payables->sum(fn (AccountPayable $p) => $p->balance()), 2);
        if ($compensation > 0 && $usd($compensation) > $owed + 0.005) {
            throw new RuntimeException('Se le debe a la agencia $' . number_format($owed, 2, ',', '.') . ': no se puede descontar más que eso.');
        }

        $path = $receipt?->store('cash-movements', 'local');
        try {
            return DB::transaction(function () use ($agencyId, $currency, $date, $from, $to, $delivered, $compensation, $rate, $usd, $payables, $data, $path, $userId) {
                $movement = CashMovement::create([
                    'kind' => CashMovement::AGENCY_SETTLEMENT,
                    'occurred_on' => $date,
                    'from_account_id' => $from->id,
                    'from_amount' => $delivered,
                    'to_account_id' => $to?->id,
                    'to_amount' => $delivered,
                    'rate' => $currency === 'VES' ? $rate : null,
                    'fee' => 0,
                    'amount_usd' => $usd($delivered),
                    'agency_id' => $agencyId,
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'receipt_path' => $path,
                    'created_by' => $userId,
                ]);
                // R16: lo descontado paga las deudas más viejas primero, y sale del efectivo de la agencia
                $left = $usd($compensation);
                foreach ($payables as $payable) {
                    if ($left <= 0.005) {
                        break;
                    }
                    $part = min($left, $payable->balance());
                    $this->payables->pay($payable, [
                        'amount' => $currency === 'VES' ? round($part * $rate, 2) : $part,
                        'currency' => $currency === 'VES' ? 'VES' : 'USD',
                        'rate' => $currency === 'VES' ? $rate : 1,
                        'paid_at' => $date,
                        'financial_account_id' => $from->id,
                        'cash_movement_id' => $movement->id,
                        'notes' => 'Descontado del efectivo que entregó el ' . date('d/m/Y', strtotime($date)),
                    ]);
                    $left = round($left - $part, 2);
                    $this->markClosurePaidIfDone($payable->fresh(), $date, $userId);
                }
                $this->ledger->syncCashMovement($movement);
                FinancialAuditLog::record('agency_cash_settled', 'cash_movement', $movement->id, null,
                    $movement->snapshot() + ['compensation' => $compensation, 'currency' => $currency], null, $userId);

                return $movement;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    /** §83: al anular una liquidación se anulan también sus descuentos; un cierre que quedó pagado con ellos vuelve a "Listo para pago". */
    public function undoCompensations(CashMovement $movement, string $reason, int $userId): void
    {
        foreach (PayablePayment::where('cash_movement_id', $movement->id)->whereNull('voided_at')->get() as $payment) {
            $this->payables->void($payment, "Se anuló la liquidación de efectivo: {$reason}");
            $payable = $payment->payable()->first();
            $closure = $payable && $payable->source_type === self::SOURCE_CLOSURE ? AgencyWeeklyClosure::find($payable->source_id) : null;
            if ($closure && $closure->status === AgencyWeeklyClosure::PAID && $closure->payment_method === self::COMPENSATION_METHOD && $payable->fresh()->balance() > 0.005) {
                $before = $closure->only(['status', 'paid_at', 'payment_amount']);
                $closure->update(['status' => AgencyWeeklyClosure::READY, 'paid_at' => null, 'paid_by' => null, 'payment_amount' => null,
                    'payment_date' => null, 'payment_method' => null]);
                DeliveryAuditLog::record('closure_payment_reverted', 'agency_weekly_closure', $closure->id, $before,
                    $closure->only(['status']) + ['reason' => 'Se anuló la liquidación de efectivo con la que se pagó'], $userId);
            }
        }
    }

    /** Las deudas abiertas con la agencia, de la más vieja a la más nueva. */
    public function openPayables(int $agencyId): Collection
    {
        return AccountPayable::where('payee_type', 'agency')->where('agency_id', $agencyId)->whereNull('voided_at')
            ->orderBy('issued_at')->orderBy('id')->get()->filter(fn (AccountPayable $p) => $p->balance() > 0.005)->values();
    }

    private function markClosurePaidIfDone(AccountPayable $payable, string $date, int $userId): void
    {
        if ($payable->source_type !== self::SOURCE_CLOSURE || $payable->balance() > 0.005) {
            return;
        }
        $closure = AgencyWeeklyClosure::find($payable->source_id);
        if ($closure && $closure->status === AgencyWeeklyClosure::READY) {
            app(ClosureReview::class)->pay($closure, ['amount' => $payable->amount, 'date' => $date, 'method' => self::COMPENSATION_METHOD], $userId);
        }
    }

    private function family(string $currency): string
    {
        return in_array($currency, self::DOLLARS, true) ? 'USD' : $currency;
    }
}
