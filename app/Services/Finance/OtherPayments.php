<?php

namespace App\Services\Finance;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\Expense;
use App\Models\FinancialAuditLog;
use App\Models\ReceivableCollection;
use App\Models\RecurringExpense;
use App\Models\RecurringExpensePayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Los demás pagos de la caja (Módulo 4 de Fran, entrega 3d):
 * - §28 y §44: un gasto manual se pagó desde una cuenta (sale ese día) o queda por pagar (una deuda con un empleado, una
 *   herramienta u otra obligación). Antes del arranque de la caja puede quedar sin indicar.
 * - §28: cada vencimiento de un gasto recurrente se paga aparte y sale de la caja ese día; la rentabilidad no cambia.
 * - §45: las otras cuentas por cobrar, con sus cobros.
 * Nada se borra: se anula con motivo (§83), y queda en la auditoría (§82).
 */
class OtherPayments
{
    public const PAYEE_TYPES = ['employee' => 'Empleado', 'tool' => 'Herramienta o servicio', 'other' => 'Otra obligación'];

    // ---------------------------------------------------------------------------------------------------------------
    // Gastos manuales (§28, §44)

    /**
     * Cómo se pagó el gasto: 'paid' (desde financial_account_id), 'payable' (deuda con payee_type, payee_name y due_date) o
     * 'none' (solo si es de antes del arranque de la caja).
     * @param array{payment?: ?string, financial_account_id?: ?int, payee_type?: ?string, payee_name?: ?string, due_date?: ?string} $data
     */
    public function applyExpensePayment(Expense $expense, array $data): void
    {
        $mode = $data['payment'] ?? null;
        $payable = AccountPayable::where('source_type', 'expense')->where('source_id', $expense->id)->whereNull('voided_at')->first();
        $mode ??= $payable ? 'payable' : ($expense->financial_account_id ? 'paid' : 'none');
        $date = $expense->expense_date->toDateString();

        if ($mode !== 'payable' && $payable) {
            $this->voidPayable($payable, 'El gasto ya no queda por pagar.');
        }
        if ($mode === 'paid') {
            CashLedger::assertAccount($data['financial_account_id'] ?? null, $expense->currency, $date);
            $expense->update(['financial_account_id' => $data['financial_account_id']]);
        } elseif ($mode === 'payable') {
            $expense->update(['financial_account_id' => null]);
            $this->expensePayable($expense, $payable, $data);
        } else {
            if ($date > CashLedger::startDate()) {
                throw ValidationException::withMessages(['payment' => 'Di cómo se pagó: desde qué cuenta, o si queda por pagar.']);
            }
            $expense->update(['financial_account_id' => null]);
        }
    }

    /** Anular un gasto: su deuda también, si no tiene pagos. */
    public function beforeVoidExpense(Expense $expense): void
    {
        $payable = AccountPayable::where('source_type', 'expense')->where('source_id', $expense->id)->whereNull('voided_at')->first();
        if ($payable) {
            $this->voidPayable($payable, 'Se anuló el gasto.');
        }
    }

    private function expensePayable(Expense $expense, ?AccountPayable $payable, array $data): void
    {
        if (!isset(self::PAYEE_TYPES[$data['payee_type'] ?? ''])) {
            throw ValidationException::withMessages(['payee_type' => 'Elige a quién se le debe: empleado, herramienta u otra obligación.']);
        }
        if (trim((string) ($data['payee_name'] ?? '')) === '') {
            throw ValidationException::withMessages(['payee_name' => 'Escribe a quién se le debe.']);
        }
        $attributes = [
            'payee_type' => $data['payee_type'],
            'payee_name' => mb_substr(trim($data['payee_name']), 0, 150),
            'description' => mb_substr($expense->concept, 0, 255),
            'currency' => $expense->currency,
            'amount' => $expense->amount,
            'rate' => $expense->rate,
            'amount_usd' => $expense->amount_usd,
            'due_date' => !empty($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : null,
        ];
        if (!$payable) {
            $payable = AccountPayable::create($attributes + ['source_type' => 'expense', 'source_id' => $expense->id, 'issued_at' => $expense->expense_date->toDateString(),
                'created_by' => auth()->id()]);
            FinancialAuditLog::record('expense_payable_created', 'account_payable', $payable->id, null, $attributes);

            return;
        }
        if ($expense->amount < $payable->paid_amount - 0.005 || ($payable->paid_amount > 0 && $payable->currency !== $expense->currency)) {
            throw ValidationException::withMessages(['amount' => 'La deuda de este gasto ya tiene pagos: no puede bajar de lo pagado ni cambiar de moneda.']);
        }
        $payable->update($attributes);
    }

    private function voidPayable(AccountPayable $payable, string $reason): void
    {
        if ($payable->paid_amount > 0.005) {
            throw ValidationException::withMessages(['payment' => 'La deuda de este gasto ya tiene pagos: anúlalos primero (Finanzas › Caja › Por pagar).']);
        }
        $payable->update(['voided_at' => now(), 'void_reason' => $reason]);
        FinancialAuditLog::record('expense_payable_voided', 'account_payable', $payable->id, ['voided' => false], ['voided' => true], $reason);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Gastos recurrentes (§28)

    /** @param array{due_date: string, paid_at: string, financial_account_id?: ?int, amount: float|string, currency: string, rate?: float|string|null, notes?: ?string} $data */
    public function payRecurring(RecurringExpense $recurring, array $data, ?UploadedFile $receipt, int $userId): RecurringExpensePayment
    {
        $usd = Expense::toUsd((float) $data['amount'], $data['currency'], isset($data['rate']) ? (float) $data['rate'] : null);
        CashLedger::assertAccount($data['financial_account_id'] ?? null, $data['currency'], $data['paid_at']);
        if (RecurringExpensePayment::where('recurring_expense_id', $recurring->id)->where('due_date', $data['due_date'])->whereNull('voided_at')->exists()) {
            throw ValidationException::withMessages(['due_date' => 'Ese vencimiento ya está pagado.']);
        }
        $payment = RecurringExpensePayment::create([
            'recurring_expense_id' => $recurring->id,
            'due_date' => $data['due_date'],
            'paid_at' => $data['paid_at'],
            'financial_account_id' => $data['financial_account_id'] ?? null,
            'amount' => round((float) $data['amount'], 2),
            'currency' => $data['currency'],
            'rate' => $usd['rate'],
            'amount_usd' => $usd['amount_usd'],
            'notes' => $data['notes'] ?? null,
            'receipt_path' => $receipt?->store('recurring-payments', 'local'),
            'created_by' => $userId,
        ]);
        FinancialAuditLog::record('recurring_payment_created', 'recurring_expense', $recurring->id, null, $payment->fresh(['account'])->toPayload(), null, $userId);

        return $payment;
    }

    public function voidRecurringPayment(RecurringExpensePayment $payment, string $reason, int $userId): void
    {
        if ($payment->voided_at) {
            throw ValidationException::withMessages(['payment' => 'Ese pago ya estaba anulado.']);
        }
        $payment->update(['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason]);
        FinancialAuditLog::record('recurring_payment_voided', 'recurring_expense', $payment->recurring_expense_id, ['voided' => false], ['voided' => true], $reason, $userId);
    }

    /**
     * §28 y §46: los vencimientos de un recurrente desde el arranque de la caja hasta $until, con el monto de su versión y si
     * ya se pagaron. Los que ya pasaron sin pagar están vencidos.
     * @return array<int, array{due_date: string, amount: float, currency: string, amount_usd: float, paid: bool, overdue: bool}>
     */
    public function dueDates(RecurringExpense $recurring, string $until): array
    {
        if (!$recurring->is_active) {
            return [];
        }
        $paid = RecurringExpensePayment::where('recurring_expense_id', $recurring->id)->whereNull('voided_at')->pluck('due_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();
        $out = [];
        $from = Carbon::parse(CashLedger::startDate())->addDay();
        $end = Carbon::parse($until);
        for ($i = 0; $i < 400 && ($next = $recurring->nextPayment($from)) && $next->lte($end); $i++) {
            $version = $recurring->versionOn($next);
            if ($version && $version->is_active && (float) $version->amount > 0) {
                $date = $next->toDateString();
                $out[] = [
                    'due_date' => $date,
                    'amount' => (float) $version->amount,
                    'currency' => $version->currency,
                    'amount_usd' => (float) $version->amount_usd,
                    'paid' => isset($paid[$date]),
                    'overdue' => !isset($paid[$date]) && $next->lt(today()),
                ];
            }
            $from = $next->copy()->addDay();
        }

        return $out;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Otras cuentas por cobrar (§45)

    /** @param array{debtor_name: string, description: string, amount: float|string, currency: string, rate?: float|string|null, issued_at?: ?string, due_date?: ?string, notes?: ?string} $data */
    public function createReceivable(array $data, int $userId): AccountReceivable
    {
        $usd = Expense::toUsd((float) $data['amount'], $data['currency'], isset($data['rate']) ? (float) $data['rate'] : null);
        $receivable = AccountReceivable::create([
            'debtor_name' => trim($data['debtor_name']),
            'description' => trim($data['description']),
            'currency' => $data['currency'],
            'amount' => round((float) $data['amount'], 2),
            'rate' => $usd['rate'],
            'amount_usd' => $usd['amount_usd'],
            'issued_at' => $data['issued_at'] ?? today()->toDateString(),
            'due_date' => $data['due_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ]);
        FinancialAuditLog::record('receivable_created', 'account_receivable', $receivable->id, null, $receivable->toPayload(), null, $userId);

        return $receivable;
    }

    /** Un cobro, parcial o total, que entra a una cuenta de la caja (como un pago de deuda, pero al revés). */
    public function collect(AccountReceivable $receivable, array $data, ?UploadedFile $receipt, int $userId): ReceivableCollection
    {
        return DB::transaction(function () use ($receivable, $data, $receipt, $userId) {
            $receivable = AccountReceivable::whereKey($receivable->id)->lockForUpdate()->firstOrFail();
            if ($receivable->voided_at) {
                throw ValidationException::withMessages(['receivable' => 'Esa cuenta por cobrar está anulada.']);
            }
            $amount = round((float) $data['amount'], 2);
            $usd = Expense::toUsd($amount, $data['currency'], isset($data['rate']) ? (float) $data['rate'] : null);
            if ($receivable->currency === 'VES' && $data['currency'] !== 'VES') {
                throw ValidationException::withMessages(['currency' => 'Esta cuenta por cobrar está en bolívares: el cobro también.']);
            }
            CashLedger::assertAccount($data['financial_account_id'] ?? null, $data['currency'], $data['collected_at']);
            $applied = $receivable->currency === 'VES' ? $amount : $usd['amount_usd'];
            if ($applied > $receivable->balance() + 0.005) {
                throw ValidationException::withMessages(['amount' => 'El cobro es mayor que lo que falta por cobrar.']);
            }
            $collection = $receivable->collections()->create([
                'amount' => $amount,
                'currency' => $data['currency'],
                'rate' => $usd['rate'],
                'amount_usd' => $usd['amount_usd'],
                'applied_amount' => round($applied, 2),
                'collected_at' => $data['collected_at'],
                'financial_account_id' => $data['financial_account_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'receipt_path' => $receipt?->store('receivables', 'local'),
                'created_by' => $userId,
            ]);
            $this->recalcReceivable($receivable);
            FinancialAuditLog::record('receivable_collected', 'account_receivable', $receivable->id, null, $collection->toPayload(), null, $userId);

            return $collection;
        });
    }

    public function voidCollection(ReceivableCollection $collection, string $reason, int $userId): void
    {
        if ($collection->voided_at) {
            throw ValidationException::withMessages(['collection' => 'Ese cobro ya estaba anulado.']);
        }
        DB::transaction(function () use ($collection, $reason, $userId) {
            $collection->update(['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason]);
            $this->recalcReceivable($collection->receivable);
            FinancialAuditLog::record('receivable_collection_voided', 'account_receivable', $collection->account_receivable_id, ['voided' => false], ['voided' => true], $reason, $userId);
        });
    }

    public function voidReceivable(AccountReceivable $receivable, string $reason, int $userId): void
    {
        if ($receivable->collections()->whereNull('voided_at')->exists()) {
            throw ValidationException::withMessages(['receivable' => 'Tiene cobros: anúlalos primero.']);
        }
        $receivable->update(['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason]);
        FinancialAuditLog::record('receivable_voided', 'account_receivable', $receivable->id, ['voided' => false], ['voided' => true], $reason, $userId);
    }

    private function recalcReceivable(AccountReceivable $receivable): void
    {
        $sums = ReceivableCollection::where('account_receivable_id', $receivable->id)->whereNull('voided_at')
            ->selectRaw('COALESCE(SUM(applied_amount), 0) AS applied, COALESCE(SUM(amount_usd), 0) AS usd')->first();
        $receivable->update(['collected_amount' => round((float) $sums->applied, 2), 'collected_usd' => round((float) $sums->usd, 2)]);
    }
}
