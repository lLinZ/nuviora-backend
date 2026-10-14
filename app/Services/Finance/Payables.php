<?php

namespace App\Services\Finance;

use App\Models\AccountPayable;
use App\Models\Expense;
use App\Models\FinancialAuditLog;
use App\Models\PayablePayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pagos de las cuentas por pagar (Módulo 4 de Fran, §17): varios pagos por deuda; cada uno reduce el saldo y no cambia el
 * costo del inventario. Un pago en otra moneda descuenta su equivalente: una deuda en USD o USDT baja lo que el pago vale
 * en USD (con la tasa que escribe el Admin, R2); una deuda en bolívares solo se paga en bolívares. Un pago no se borra:
 * se anula con motivo y la deuda vuelve a subir (§83). Cada pago y cada anulación quedan en la auditoría (§82).
 */
class Payables
{
    /**
     * @param array{amount: float|string, currency: string, rate?: float|string|null, paid_at?: string|null,
     *              company_account_id?: int|null, notes?: string|null} $data
     */
    public function pay(AccountPayable $payable, array $data, ?UploadedFile $receipt = null): PayablePayment
    {
        return DB::transaction(function () use ($payable, $data, $receipt) {
            $payable = AccountPayable::whereKey($payable->id)->lockForUpdate()->firstOrFail();
            $amount = round((float) $data['amount'], 2);
            $currency = $data['currency'];
            $usd = Expense::toUsd($amount, $currency, isset($data['rate']) ? (float) $data['rate'] : null);

            if ($payable->currency === 'VES' && $currency !== 'VES') {
                throw ValidationException::withMessages(['currency' => 'Esta deuda está en bolívares: el pago también va en bolívares.']);
            }
            $applied = $payable->currency === 'VES' ? $amount : $usd['amount_usd'];
            $balance = $payable->balance();
            if ($applied > $balance + 0.005) {
                $unit = $payable->currency === 'VES' ? 'Bs ' : '$';
                throw ValidationException::withMessages(['amount' => "El pago ({$unit}" . number_format($applied, 2, ',', '.')
                    . ") es mayor que el saldo ({$unit}" . number_format($balance, 2, ',', '.') . ').']);
            }

            $payment = $payable->payments()->create([
                'amount' => $amount,
                'currency' => $currency,
                'rate' => $usd['rate'],
                'amount_usd' => $usd['amount_usd'],
                'applied_amount' => round($applied, 2),
                'paid_at' => Carbon::parse($data['paid_at'] ?? today())->toDateString(),
                'company_account_id' => $data['company_account_id'] ?? null,
                'receipt_path' => $receipt?->store('payables', 'local'),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $this->recalculate($payable);
            FinancialAuditLog::record('payable_payment_created', 'account_payable', $payable->id,
                ['balance' => $balance], $payment->fresh(['account'])->snapshot() + ['balance' => $payable->fresh()->balance()]);

            return $payment;
        });
    }

    public function void(PayablePayment $payment, string $reason): PayablePayment
    {
        if ($payment->voided_at) {
            throw ValidationException::withMessages(['payment' => 'Ese pago ya estaba anulado.']);
        }

        return DB::transaction(function () use ($payment, $reason) {
            $payable = AccountPayable::whereKey($payment->account_payable_id)->lockForUpdate()->firstOrFail();
            $before = $payable->balance();
            $payment->update(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => $reason]);
            $this->recalculate($payable);
            FinancialAuditLog::record('payable_payment_voided', 'account_payable', $payable->id,
                $payment->snapshot() + ['balance' => $before], ['voided' => true, 'balance' => $payable->fresh()->balance()], $reason);

            return $payment->fresh();
        });
    }

    /** Lo pagado es la suma de los pagos que no están anulados. */
    public function recalculate(AccountPayable $payable): void
    {
        $sums = PayablePayment::where('account_payable_id', $payable->id)->whereNull('voided_at')
            ->selectRaw('COALESCE(SUM(applied_amount), 0) AS applied, COALESCE(SUM(amount_usd), 0) AS usd')->first();
        $payable->update(['paid_amount' => round((float) $sums->applied, 2), 'paid_usd' => round((float) $sums->usd, 2)]);
    }
}
