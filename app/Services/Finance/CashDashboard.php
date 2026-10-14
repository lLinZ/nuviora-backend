<?php

namespace App\Services\Finance;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction as Tx;
use App\Models\RecurringExpense;

/**
 * El tablero de la caja (Módulo 4 de Fran, §46) y la tarjeta de Liquidez del Resumen (§48): caja disponible, dinero
 * pendiente en agencias, cuentas por cobrar, cuentas por pagar, entradas y salidas del período y próximos pagos.
 * - Todo en USD aproximado: los saldos en Bs con la tasa de Binance (Fran, 12/10); las deudas en Bs, a su tasa.
 * - §110: cuánto se puede destinar a comprar inventario (caja − por pagar − recurrentes de los próximos días).
 * - Entradas y salidas: lo que entró al negocio y lo que salió. Los movimientos entre cuentas de la empresa (transferencias,
 *   cambios de moneda, liquidaciones de efectivo) no son ni una cosa ni la otra (§39, §41, §43), y lo de antes del saldo
 *   inicial de cada cuenta ya está en ese saldo.
 */
class CashDashboard
{
    /** Cuántos días hacia adelante son "próximos pagos". */
    public const UPCOMING_DAYS = 30;

    public function __construct(private CashLedger $ledger, private OtherPayments $other)
    {
    }

    public function build(string $from, string $to): array
    {
        $accounts = $this->ledger->balances()->where('is_active', true);
        $usd = fn ($rows) => round($rows->sum(fn ($a) => CashLedger::toUsdNow((float) $a['balance'], $a['currency']) ?? 0), 2);
        $available = $usd($accounts->where('kind', '!=', FinancialAccount::AGENCY_CASH));
        $agencies = $usd($accounts->where('kind', FinancialAccount::AGENCY_CASH));

        $receivables = AccountReceivable::whereNull('voided_at')->get()->filter(fn (AccountReceivable $r) => $r->balance() > 0.005);
        $otherReceivables = round($receivables->sum(fn (AccountReceivable $r) => $r->balanceUsd()), 2);
        $payables = AccountPayable::with(['supplier:id,name', 'agency:id,names'])->whereNull('voided_at')->get()
            ->filter(fn (AccountPayable $p) => $p->balance() > 0.005);

        $flows = Tx::join('financial_accounts as fa', 'fa.id', '=', 'financial_transactions.financial_account_id')
            ->whereNull('financial_transactions.voided_at')->whereIn('financial_transactions.status', Tx::COUNTED)
            ->whereNotIn('financial_transactions.kind', Tx::INTERNAL)
            ->whereColumn('financial_transactions.occurred_on', '>', 'fa.opening_date')
            ->whereBetween('financial_transactions.occurred_on', [$from, $to])
            ->groupBy('financial_transactions.kind')
            ->selectRaw('financial_transactions.kind AS kind,
                SUM(CASE WHEN financial_transactions.amount_usd > 0 THEN financial_transactions.amount_usd ELSE 0 END) AS inflow,
                SUM(CASE WHEN financial_transactions.amount_usd < 0 THEN -financial_transactions.amount_usd ELSE 0 END) AS outflow')
            ->get();
        $breakdown = fn (string $field) => $flows->filter(fn ($f) => (float) $f->{$field} > 0.005)->sortByDesc(fn ($f) => (float) $f->{$field})
            ->map(fn ($f) => ['kind' => $f->kind, 'label' => Tx::KIND_LABEL[$f->kind] ?? $f->kind, 'usd' => round((float) $f->{$field}, 2)])->values();

        $upcoming = $this->upcoming($payables);
        $payable = round($payables->sum(fn (AccountPayable $p) => $p->balanceUsd()), 2);
        $recurringSoon = round(collect($upcoming)->where('type', 'recurring')->sum('amount_usd'), 2);

        return [
            'from' => $from,
            'to' => $to,
            'binance_rate' => CashLedger::binanceRate(),
            'available_usd' => $available,
            'agencies_usd' => $agencies,
            // §45: lo que se cobra es el efectivo que tienen las agencias más las otras cuentas por cobrar
            'receivable_usd' => round($agencies + $otherReceivables, 2),
            'other_receivable_usd' => $otherReceivables,
            'payable_usd' => $payable,
            'payable_overdue_usd' => round($payables->filter(fn (AccountPayable $p) => $p->status() === 'overdue')->sum(fn (AccountPayable $p) => $p->balanceUsd()), 2),
            'inflow_usd' => round($flows->sum(fn ($f) => (float) $f->inflow), 2),
            'outflow_usd' => round($flows->sum(fn ($f) => (float) $f->outflow), 2),
            'inflow_by_kind' => $breakdown('inflow'),
            'outflow_by_kind' => $breakdown('outflow'),
            'upcoming' => $upcoming,
            'upcoming_usd' => round(collect($upcoming)->sum('amount_usd'), 2),
            'upcoming_days' => self::UPCOMING_DAYS,
            // §110 "¿Cuánto puedo destinar a comprar inventario?": la caja menos lo que se debe y los gastos recurrentes que vienen.
            // El efectivo de las agencias no cuenta: todavía no está en la mano. Decisión nuestra; confirmar con Fran.
            'inventory_capital_usd' => round($available - $payable - $recurringSoon, 2),
            'recurring_soon_usd' => $recurringSoon,
        ];
    }

    /**
     * §46 "Próximos pagos": las deudas con saldo (las vencidas primero, después por fecha; las que no tienen fecha al final) y
     * los vencimientos sin pagar de los gastos recurrentes, hasta dentro de UPCOMING_DAYS días.
     * @return array<int, array{type: string, date: ?string, payee: string, description: string, amount: float, currency: string, amount_usd: float, overdue: bool, payable_id: ?int, closure_id: ?int, recurring_id: ?int}>
     */
    private function upcoming($payables): array
    {
        $until = today()->addDays(self::UPCOMING_DAYS)->toDateString();
        $rows = [];
        foreach ($payables as $p) {
            $due = $p->due_date?->toDateString();
            if ($due !== null && $due > $until) {
                continue;
            }
            $rows[] = [
                'type' => 'payable', 'date' => $due, 'payee' => $p->payeeName(), 'description' => $p->description, 'amount' => $p->balance(),
                'currency' => $p->currency, 'amount_usd' => $p->balanceUsd(), 'overdue' => $p->status() === 'overdue', 'payable_id' => $p->id,
                'closure_id' => $p->source_type === AgencyCash::SOURCE_CLOSURE ? $p->source_id : null, 'recurring_id' => null,
            ];
        }
        foreach (RecurringExpense::where('is_active', true)->get() as $r) {
            foreach ($this->other->dueDates($r, $until) as $d) {
                if (!$d['paid']) {
                    $rows[] = [
                        'type' => 'recurring', 'date' => $d['due_date'], 'payee' => $r->name, 'description' => 'Gasto recurrente', 'amount' => $d['amount'],
                        'currency' => $d['currency'], 'amount_usd' => $d['amount_usd'], 'overdue' => $d['overdue'], 'payable_id' => null, 'closure_id' => null,
                        'recurring_id' => $r->id,
                    ];
                }
            }
        }
        usort($rows, fn ($a, $b) => [$a['date'] === null, $a['date']] <=> [$b['date'] === null, $b['date']]);

        return $rows;
    }
}
