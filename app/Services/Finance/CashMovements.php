<?php

namespace App\Services\Finance;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialAuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Los movimientos de caja que se registran a mano (Módulo 4 de Fran, §37 a §41, §83; casos 22 a 25 del §108):
 * - aporte (§37) y retiro (§38) del propietario: suben o bajan la caja, sin tocar el beneficio;
 * - transferencia interna (§39): sale de una cuenta y entra a otra de la misma moneda. Lo que sale de más es la comisión
 *   (§40), que sí es gasto: un gasto general "Comisiones bancarias" ligado al movimiento;
 * - cambio de moneda (§41): monto de origen, tasa y monto de destino, y la comisión aparte (también gasto);
 * - ajuste de saldo (§83): para cuadrar con el banco, con motivo. No toca el beneficio.
 * Todo con la tasa a mano (R2) y comprobante opcional (§79). No se editan: se anulan con el motivo y se registran de nuevo.
 */
class CashMovements
{
    public const FEE_CATEGORY = 'Comisiones bancarias';

    private const DOLLARS = ['USD', 'USDT'];

    public function __construct(private CashLedger $ledger)
    {
    }

    /**
     * @param array{kind: string, occurred_on: string, from_account_id?: ?int, from_amount?: float|string|null, to_account_id?: ?int,
     *              to_amount?: float|string|null, rate?: float|string|null, fee?: float|string|null, reference?: ?string, notes?: ?string} $data
     */
    public function create(array $data, ?UploadedFile $receipt, int $userId): CashMovement
    {
        $kind = $data['kind'];
        $from = !empty($data['from_account_id']) ? FinancialAccount::find($data['from_account_id']) : null;
        $to = !empty($data['to_account_id']) ? FinancialAccount::find($data['to_account_id']) : null;
        $fromAmount = isset($data['from_amount']) && $data['from_amount'] !== '' ? round((float) $data['from_amount'], 2) : null;
        $toAmount = isset($data['to_amount']) && $data['to_amount'] !== '' ? round((float) $data['to_amount'], 2) : null;
        $rate = isset($data['rate']) && $data['rate'] !== '' && $data['rate'] !== null ? (float) $data['rate'] : null;
        $fee = round((float) ($data['fee'] ?? 0), 2);
        $date = $data['occurred_on'];

        [$from, $fromAmount, $to, $toAmount, $rate, $fee, $usd] = match ($kind) {
            CashMovement::OWNER_CONTRIBUTION => $this->single(null, null, $this->need($to, 'la cuenta donde entra'), $this->positive($toAmount), $rate),
            CashMovement::OWNER_WITHDRAWAL => $this->single($this->need($from, 'la cuenta de donde sale'), $this->positive($fromAmount), null, null, $rate),
            CashMovement::ADJUSTMENT => $this->adjustment($from, $fromAmount, $to, $toAmount, $rate, $data['notes'] ?? null),
            CashMovement::INTERNAL_TRANSFER => $this->transfer($this->need($from, 'la cuenta de donde sale'), $this->positive($fromAmount),
                $this->need($to, 'la cuenta donde entra'), $toAmount ?? $fromAmount, $rate),
            CashMovement::EXCHANGE => $this->exchange($this->need($from, 'la cuenta de donde sale'), $this->positive($fromAmount),
                $this->need($to, 'la cuenta donde entra'), $this->positive($toAmount), $rate, $fee),
            default => throw new RuntimeException('Tipo de movimiento no válido.'),
        };
        foreach ([$from, $to] as $account) {
            if (!$account) {
                continue;
            }
            if ($account->isAgencyCash()) {
                throw new RuntimeException('El efectivo de una agencia se mueve con "Liquidar efectivo", no aquí.');
            }
            if (!$account->is_active) {
                throw new RuntimeException("La cuenta {$account->name} está inactiva.");
            }
            if ($date <= $account->opening_date->toDateString()) {
                throw new RuntimeException("La fecha tiene que ser después del saldo inicial de {$account->name} ("
                    . $account->opening_date->format('d/m/Y') . '): lo de antes ya está en ese saldo.');
            }
        }

        $path = $receipt?->store('cash-movements', 'local');
        try {
            return DB::transaction(function () use ($kind, $date, $from, $fromAmount, $to, $toAmount, $rate, $fee, $usd, $data, $path, $userId) {
                $movement = CashMovement::create([
                    'kind' => $kind,
                    'occurred_on' => $date,
                    'from_account_id' => $from?->id,
                    'from_amount' => $fromAmount,
                    'to_account_id' => $to?->id,
                    'to_amount' => $toAmount,
                    'rate' => $rate,
                    'fee' => $fee,
                    'amount_usd' => $usd,
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'receipt_path' => $path,
                    'created_by' => $userId,
                ]);
                if ($fee > 0) {
                    $this->feeExpense($movement, $from, $userId);
                }
                $this->ledger->syncCashMovement($movement);
                FinancialAuditLog::record('cash_movement_created', 'cash_movement', $movement->id, null, $movement->snapshot(), null, $userId);

                return $movement;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    /** §83: se anula con el motivo. Sus movimientos de cuenta y el gasto de su comisión también. */
    public function void(CashMovement $movement, string $reason, int $userId): void
    {
        if ($movement->voided_at) {
            throw new RuntimeException('Ese movimiento ya estaba anulado.');
        }
        DB::transaction(function () use ($movement, $reason, $userId) {
            $movement->update(['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason]);
            if ($movement->kind === CashMovement::AGENCY_SETTLEMENT) {
                app(AgencyCash::class)->undoCompensations($movement, $reason, $userId);
            }
            Expense::where('cash_movement_id', $movement->id)->whereNull('voided_at')
                ->update(['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => "Se anuló el movimiento de caja: {$reason}"]);
            $this->ledger->syncCashMovement($movement);
            FinancialAuditLog::record('cash_movement_voided', 'cash_movement', $movement->id, ['voided' => false], ['voided' => true], $reason, $userId);
        });
    }

    // ---------------------------------------------------------------------------------------------------------------

    /** Aporte o retiro: una sola cuenta. */
    private function single(?FinancialAccount $from, ?float $fromAmount, ?FinancialAccount $to, ?float $toAmount, ?float $rate): array
    {
        $account = $from ?? $to;
        $amount = $fromAmount ?? $toAmount;
        $rate = $this->rateFor($account->currency, $rate);

        return [$from, $fromAmount, $to, $toAmount, $rate, 0.0, $this->usd($amount, $account->currency, $rate)];
    }

    /** §83: un ajuste sube (cuenta destino) o baja (cuenta origen) una cuenta, siempre con motivo. */
    private function adjustment(?FinancialAccount $from, ?float $fromAmount, ?FinancialAccount $to, ?float $toAmount, ?float $rate, ?string $notes): array
    {
        if (trim((string) $notes) === '') {
            throw new RuntimeException('Escribe el motivo del ajuste.');
        }
        if (($from === null) === ($to === null)) {
            throw new RuntimeException('Un ajuste sube o baja una sola cuenta.');
        }

        return $from ? $this->single($from, $this->positive($fromAmount), null, null, $rate) : $this->single(null, null, $to, $this->positive($toAmount), $rate);
    }

    /** §39 y §40: misma moneda; lo que sale de más es la comisión. */
    private function transfer(FinancialAccount $from, float $sent, FinancialAccount $to, ?float $received, ?float $rate): array
    {
        if ($from->id === $to->id) {
            throw new RuntimeException('La cuenta de origen y la de destino son la misma.');
        }
        if ($this->family($from->currency) !== $this->family($to->currency)) {
            throw new RuntimeException('Son monedas distintas: eso es un cambio de moneda.');
        }
        $received = $this->positive($received);
        if ($received > $sent + 0.001) {
            throw new RuntimeException('Lo que llega no puede ser más de lo que sale.');
        }
        $rate = $this->rateFor($from->currency, $rate);

        return [$from, $sent, $to, $received, $rate, round($sent - $received, 2), $this->usd($received, $to->currency, $rate)];
    }

    /** §41: monedas distintas. La tasa sale de los dos montos; la comisión va aparte, en la moneda de origen. */
    private function exchange(FinancialAccount $from, float $fromAmount, FinancialAccount $to, float $toAmount, ?float $rate, float $fee): array
    {
        if ($this->family($from->currency) === $this->family($to->currency)) {
            throw new RuntimeException('Es la misma moneda: eso es una transferencia entre cuentas.');
        }
        if ($fee < 0) {
            throw new RuntimeException('La comisión no puede ser negativa.');
        }
        if ($this->isDollar($to->currency)) {
            $usd = $toAmount;
            $rate = round($fromAmount / $toAmount, 4);
        } elseif ($this->isDollar($from->currency)) {
            $usd = $fromAmount;
            $rate = round($toAmount / $fromAmount, 4);
        } else {
            // Bs ↔ euros: hace falta la tasa en Bs por USD para saber cuánto es en dólares
            if (!$rate || $rate <= 0) {
                throw new RuntimeException('Escribe la tasa en Bs por USD.');
            }
            $usd = round(($from->currency === 'VES' ? $fromAmount : $toAmount) / $rate, 2);
        }

        return [$from, $fromAmount, $to, $toAmount, $rate, $fee, round($usd, 2)];
    }

    /** §40 y caso 25: la comisión es un gasto general. */
    private function feeExpense(CashMovement $movement, FinancialAccount $from, int $userId): void
    {
        $category = ExpenseCategory::firstOrCreate(['name' => self::FEE_CATEGORY]);
        $usd = $this->feeUsd($movement, $from);
        $currency = in_array($from->currency, Expense::CURRENCIES, true) ? $from->currency : 'USD';
        Expense::create([
            'concept' => $movement->kind === CashMovement::EXCHANGE ? 'Comisión del cambio de moneda' : 'Comisión de transferencia',
            'expense_category_id' => $category->id,
            'amount' => $currency === $from->currency ? $movement->fee : $usd,
            'currency' => $currency,
            'rate' => $currency === 'VES' ? round($movement->fee / max($usd, 0.01), 4) : 1,
            'amount_usd' => $usd,
            'expense_date' => $movement->occurred_on->toDateString(),
            'description' => 'De un movimiento de caja (' . (CashMovement::KIND_LABEL[$movement->kind] ?? $movement->kind) . '). Se anula con el movimiento.',
            'cash_movement_id' => $movement->id,
            'created_by' => $userId,
        ]);
    }

    /** La comisión en USD, con la tasa del movimiento. */
    public static function feeUsd(CashMovement $movement, FinancialAccount $from): float
    {
        if (in_array($from->currency, self::DOLLARS, true)) {
            return round($movement->fee, 2);
        }
        if ($movement->kind === CashMovement::EXCHANGE && $movement->from_amount > 0 && $movement->amount_usd > 0) {
            return round($movement->fee * $movement->amount_usd / $movement->from_amount, 2);
        }

        return $movement->rate > 0 ? round($movement->fee / $movement->rate, 2) : 0.0;
    }

    private function rateFor(string $currency, ?float $rate): float
    {
        if ($this->isDollar($currency)) {
            return 1.0;
        }
        if (!$rate || $rate <= 0) {
            throw new RuntimeException($currency === 'VES' ? 'Escribe la tasa (Bs por USD).' : 'Escribe la tasa (euros por USD).');
        }

        return $rate;
    }

    private function usd(float $amount, string $currency, float $rate): float
    {
        return round($this->isDollar($currency) ? $amount : $amount / $rate, 2);
    }

    private function need(?FinancialAccount $account, string $what): FinancialAccount
    {
        return $account ?? throw new RuntimeException("Elige {$what}.");
    }

    private function positive(?float $amount): float
    {
        if ($amount === null || $amount <= 0) {
            throw new RuntimeException('El monto tiene que ser mayor que 0.');
        }

        return $amount;
    }

    private function isDollar(string $currency): bool
    {
        return in_array($currency, self::DOLLARS, true);
    }

    private function family(string $currency): string
    {
        return $this->isDollar($currency) ? 'USD' : $currency;
    }
}
