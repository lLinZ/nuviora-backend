<?php

namespace App\Services\Finance;

use App\Constants\OrderStatus;
use App\Models\CashMovement;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction as Tx;
use App\Models\Expense;
use App\Models\InventoryTransferTrip;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PayablePayment;
use App\Models\ReceivableCollection;
use App\Models\RecurringExpensePayment;
use App\Models\ReconciliationItem;
use App\Models\Setting;
use App\Models\Status;
use App\Models\User;
use App\Services\EarningsService;
use App\Services\Payments\ReceiptChecker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El libro de la caja (Módulo 4 de Fran, entrega 3; §32 a §46, §75, §76, R14). Cada movimiento de una cuenta nace de un
 * origen y se mantiene igual a él:
 * - si cambia el dinero (cuenta, fecha, monto o tasa), el movimiento se anula con el motivo y nace otro; si solo cambia el
 *   estado, se actualiza. Nada se borra (§83);
 * - cuenta lo que pasó después del saldo inicial de su cuenta (el del cierre del 14/10, R14).
 *
 * Orígenes de la operación (3a):
 * - los pagos digitales de la conciliación del Módulo 1 (§75): entran a su cuenta el día que se pagaron, aunque la orden
 *   no esté entregada (anticipo, §76; el ingreso nace al entregar, en el P&L);
 * - el efectivo que cobró la agencia al entregar (§42), igual que en su liquidación;
 * - el vuelto que dio la empresa.
 */
class CashLedger
{
    public const START_KEY = 'finance.cash_start_date';

    /** Los métodos del Módulo 1 que se pagan en bolívares. */
    private const VES_KINDS = ['pago_movil', 'transferencia'];

    /** El vuelto de la empresa, por el método del Módulo 1 por donde sale. */
    private const CHANGE_KIND = [
        'BOLIVARES_PAGOMOVIL' => 'pago_movil', 'BOLIVARES_TRANSFERENCIA' => 'transferencia', 'ZELLE_DOLARES' => 'zelle',
        'BINANCE_DOLARES' => 'binance', 'PAYPAL_DOLARES' => 'paypal', 'ZINLI_DOLARES' => 'zinli',
    ];

    private const CURRENCY_LABEL = ['VES' => 'Bs', 'USD' => 'USD', 'USDT' => 'USDT', 'EUR' => 'EUR'];

    /** @var array<int, ?FinancialAccount> por company_account_id */
    private array $byCompanyAccount = [];

    /** @var array<string, ?FinancialAccount> por método del Módulo 1 */
    private array $byMethod = [];

    /** @var array<string, FinancialAccount> por agencia|moneda */
    private array $agencyAccounts = [];

    private ?int $deliveredId = null;

    private ?string $earliest = null;

    public static function startDate(): string
    {
        return (string) (Setting::get(self::START_KEY) ?: '2026-10-14');
    }

    /** La tasa de Binance para mostrar en USD un saldo en Bs (Fran, 2026-10-12): la última de `currencies`. */
    public static function binanceRate(): float
    {
        $rate = (float) Currency::where('description', 'binance_usd')->orderByDesc('id')->value('value');

        return $rate > 0 ? $rate : (float) (Setting::get('rate_binance_usd', 0) ?: 0);
    }

    /** Un saldo en su moneda pasado a USD con las tasas de hoy (aproximado en Bs y EUR). */
    public static function toUsdNow(float $amount, string $currency): ?float
    {
        if (in_array($currency, ['USD', 'USDT'], true)) {
            return round($amount, 2);
        }
        if ($currency === 'VES') {
            $rate = self::binanceRate();

            return $rate > 0 ? round($amount / $rate, 2) : null;
        }
        $eur = (float) (Setting::get('rate_bcv_eur', 0) ?: 0);
        $usd = (float) (Setting::get('rate_bcv_usd', 0) ?: 0);

        return $eur > 0 && $usd > 0 ? round($amount * $eur / $usd, 2) : null;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Pagos digitales (conciliación del Módulo 1)

    /** §75 y §76: un pago digital entra a su cuenta el día que se pagó, con el estado de su conciliación. */
    public function syncReconciliationItem(ReconciliationItem|int $item, bool $deleted = false): void
    {
        $id = $item instanceof ReconciliationItem ? $item->id : $item;
        $model = $deleted ? null : ReconciliationItem::with(['day', 'order.payments'])->find($id);
        $reason = !$model ? 'El pago salió de la conciliación.'
            : ($model->status === ReconciliationItem::NOT_RECEIVED ? 'En la conciliación se confirmó como no recibido.' : null);
        $this->apply('reconciliation_item', $id, $model ? $this->itemLegs($model) : [], $reason);
    }

    /** Los pagos de la conciliación de una cuenta del Módulo 1, otra vez (cuando cambia la cuenta de la caja a la que va). */
    public function syncCompanyAccount(int $companyAccountId): void
    {
        unset($this->byCompanyAccount[$companyAccountId]);
        $this->byMethod = [];
        $since = $this->earliestOpening();
        ReconciliationItem::where('company_account_id', $companyAccountId)
            ->whereHas('day', fn ($q) => $q->where('date', '>=', $since))
            ->pluck('id')->each(fn ($id) => $this->syncReconciliationItem($id));
    }

    private function itemLegs(ReconciliationItem $item): array
    {
        if ($item->status === ReconciliationItem::NOT_RECEIVED) {
            return [];
        }
        $account = $this->accountOfCompanyAccount($item->company_account_id);
        $date = ($item->paid_on ?? $item->day?->date)?->toDateString();
        if (!$account || !$date || !$this->counts($account, $date)) {
            return [];
        }

        $payments = $item->order?->payments ?? collect();
        $family = in_array($item->method, self::VES_KINDS, true) ? 'bs' : $item->method;
        $ofMethod = $payments->filter(fn ($p) => $this->family(ReceiptChecker::METHOD_KIND[$p->method] ?? null) === $family);
        $vesRate = (float) ($ofMethod->firstWhere('rate', '>', 0)?->rate ?: 0) ?: self::binanceRate();

        $currency = $item->currency ?: $account->currency;
        $amount = $item->amount;
        if ($amount === null) {
            // Comprobante sin datos leídos: lo que dice el pago de la orden
            $amount = $currency === 'VES'
                ? $ofMethod->sum(fn ($p) => (float) $p->amount * ((float) $p->rate ?: $vesRate))
                : (float) $ofMethod->sum('amount');
        }
        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            return [];
        }
        [$amount, $rate, $usd] = $this->inAccountCurrency($amount, $currency, $account->currency, $vesRate);

        $status = match ($item->status) {
            ReconciliationItem::MATCHED, ReconciliationItem::CONFIRMED, ReconciliationItem::LINKED => Tx::CONFIRMED,
            ReconciliationItem::NOT_FOUND => Tx::NOT_FOUND,
            default => Tx::PENDING,
        };

        return [[
            'leg' => 'main',
            'financial_account_id' => $account->id,
            'occurred_on' => $date,
            'amount' => $amount,
            'currency' => $account->currency,
            'rate' => $rate,
            'amount_usd' => $usd,
            'kind' => Tx::CUSTOMER_PAYMENT,
            'status' => $status,
            'order_id' => $item->order_id,
            'description' => 'Pago de ' . ($item->order_name ?? "la orden {$item->order_id}") . ' por ' . (ReceiptChecker::KIND_LABEL[$item->method] ?? $item->method),
        ]];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Órdenes entregadas: efectivo en la agencia y vuelto de la empresa

    /**
     * §42: lo que la agencia cobró en efectivo (menos el vuelto que dio) entra a su "Efectivo pendiente" al entregar; el
     * vuelto que dio la empresa sale de su cuenta. También pone al día los pagos digitales de estas órdenes (su tasa sale
     * de los pagos de la orden).
     * @param int[] $orderIds
     */
    public function syncOrders(array $orderIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        $since = $this->earliestOpening();
        $after = Carbon::parse($since)->addDay()->toDateString();
        foreach (array_chunk($ids, 500) as $chunk) {
            // Solo las que pueden tener movimientos: entregadas después del saldo inicial, o que ya los tienen
            $relevant = DB::table('orders')->whereIn('id', $chunk)->where('status_id', $this->deliveredId())->where('delivered_at', '>=', $after)->pluck('id')
                ->merge(Tx::whereNull('voided_at')->where('source_type', 'order')->whereIn('source_id', $chunk)->pluck('source_id'))
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
            $orders = Order::with(['payments', 'agency:id,names'])->whereIn('id', $relevant ?: [0])->get()->keyBy('id');
            $legs = [];
            $reasons = [];
            foreach ($relevant as $id) {
                $order = $orders->get($id);
                $legs[$id] = $order ? $this->orderLegs($order) : [];
                $reasons[$id] = !$order ? 'La orden ya no existe.'
                    : ((int) $order->status_id !== $this->deliveredId() ? 'La orden dejó de estar entregada.' : null);
            }
            $this->applyMany('order', $legs, $reasons);

            $items = ReconciliationItem::with(['day', 'order.payments'])->whereIn('order_id', $chunk)
                ->where(fn ($q) => $q->whereHas('day', fn ($d) => $d->where('date', '>=', $since))
                    ->orWhereIn('id', Tx::whereNull('voided_at')->where('source_type', 'reconciliation_item')->select('source_id')))
                ->get();
            foreach ($items as $item) {
                $reason = $item->status === ReconciliationItem::NOT_RECEIVED ? 'En la conciliación se confirmó como no recibido.' : null;
                $this->apply('reconciliation_item', $item->id, $this->itemLegs($item), $reason);
            }
        }
    }

    private function orderLegs(Order $order): array
    {
        if ((int) $order->status_id !== $this->deliveredId() || !$order->delivered_at) {
            return [];
        }
        $date = Carbon::parse($order->delivered_at)->toDateString(); // orders.delivered_at no tiene cast
        if ($date <= $this->earliestOpening()) {
            return []; // ya está en los saldos iniciales (y así no se crean cuentas de agencias viejas)
        }
        $cash = EarningsService::orderCash($order, (float) (Setting::get('rate_binance_usd', 1) ?: 1), (float) (Setting::get('rate_bcv_eur', 1) ?: 1));
        $legs = [];

        $parts = [
            'USD' => [round($cash['net_usd'], 2), round($cash['net_usd'], 2)],
            'VES' => [round($cash['net_ves'], 2), round($cash['collected_ves_usd'] - $cash['change_ves_usd'], 2)],
        ];
        foreach ($parts as $currency => [$amount, $usd]) {
            if (abs($amount) < 0.005) {
                continue;
            }
            $account = $this->agencyCashAccount($order->agency_id, $currency);
            if (!$this->counts($account, $date)) {
                continue;
            }
            $legs[] = [
                'leg' => 'cash_' . strtolower($currency),
                'financial_account_id' => $account->id,
                'occurred_on' => $date,
                'amount' => $amount,
                'currency' => $currency,
                'rate' => $currency === 'VES' ? ($usd != 0 ? round($amount / $usd, 4) : self::binanceRate()) : 1.0,
                'amount_usd' => $usd,
                'kind' => Tx::AGENCY_CASH,
                'status' => Tx::CONFIRMED,
                'order_id' => $order->id,
                'description' => "Efectivo de {$order->name}" . ($order->agency ? " (cobró {$order->agency->names})" : ''),
            ];
        }

        $change = round((float) $cash['change_company'], 2);
        $kind = self::CHANGE_KIND[$cash['method_company'] ?? ''] ?? null;
        $account = $kind ? $this->accountOfMethod($kind) : null;
        if ($change > 0.005 && $account && $this->counts($account, $date)) {
            $rate = $account->currency === 'VES'
                ? (float) ($order->change_rate ?: $order->payments->firstWhere('eur_rate', '>', 0)?->eur_rate ?: (Setting::get('rate_bcv_eur', 0) ?: self::binanceRate()))
                : 1.0;
            $amount = $account->currency === 'VES' ? round($change * $rate, 2) : $change;
            $legs[] = [
                'leg' => 'change',
                'financial_account_id' => $account->id,
                'occurred_on' => $date,
                'amount' => -$amount,
                'currency' => $account->currency,
                'rate' => round($rate, 4),
                'amount_usd' => -$change,
                'kind' => Tx::COMPANY_CHANGE,
                'status' => Tx::CONFIRMED,
                'order_id' => $order->id,
                'description' => "Vuelto de {$order->name}",
            ];
        }

        return $legs;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Movimientos a mano (§37 a §41, §83)

    public function syncCashMovement(CashMovement|int $movement): void
    {
        $id = $movement instanceof CashMovement ? $movement->id : $movement;
        $m = CashMovement::with(['fromAccount', 'toAccount'])->find($id);
        $reason = !$m ? 'El movimiento ya no existe.' : ($m->voided_at ? "Se anuló el movimiento: {$m->void_reason}" : null);
        $this->apply('cash_movement', $id, $m && !$m->voided_at ? $this->movementLegs($m) : [], $reason);
    }

    private function movementLegs(CashMovement $m): array
    {
        $date = $m->occurred_on->toDateString();
        $description = (CashMovement::KIND_LABEL[$m->kind] ?? $m->kind) . ($m->notes ? ": {$m->notes}" : '');
        $leg = fn (string $leg, FinancialAccount $a, float $amount, string $kind, float $usd) => [
            'leg' => $leg,
            'financial_account_id' => $a->id,
            'occurred_on' => $date,
            'amount' => round($amount, 2),
            'currency' => $a->currency,
            'rate' => in_array($a->currency, ['USD', 'USDT'], true) || abs($usd) < 0.005 ? 1.0 : round(abs($amount / $usd), 4),
            'amount_usd' => round($usd, 2),
            'kind' => $kind,
            'status' => Tx::CONFIRMED,
            'order_id' => null,
            'description' => mb_substr($description, 0, 255),
        ];
        $usd = (float) $m->amount_usd;
        $fee = $m->fee > 0 && $m->fromAccount
            ? $leg('fee', $m->fromAccount, -$m->fee, $m->kind === CashMovement::EXCHANGE ? Tx::EXCHANGE_FEE : Tx::TRANSFER_FEE, -CashMovements::feeUsd($m, $m->fromAccount))
            : null;

        return array_values(array_filter(match ($m->kind) {
            CashMovement::OWNER_CONTRIBUTION => [$leg('in', $m->toAccount, $m->to_amount, Tx::OWNER_CONTRIBUTION, $usd)],
            CashMovement::OWNER_WITHDRAWAL => [$leg('out', $m->fromAccount, -$m->from_amount, Tx::OWNER_WITHDRAWAL, -$usd)],
            CashMovement::ADJUSTMENT => [$m->toAccount
                ? $leg('in', $m->toAccount, $m->to_amount, Tx::ADJUSTMENT, $usd)
                : $leg('out', $m->fromAccount, -$m->from_amount, Tx::ADJUSTMENT, -$usd)],
            // §39: lo que llega sale de la cuenta de origen; lo que salió de más es la comisión (§40)
            CashMovement::INTERNAL_TRANSFER => [
                $leg('out', $m->fromAccount, -$m->to_amount, Tx::TRANSFER_OUT, -$usd),
                $leg('in', $m->toAccount, $m->to_amount, Tx::TRANSFER_IN, $usd),
                $fee,
            ],
            CashMovement::EXCHANGE => [
                $leg('out', $m->fromAccount, -$m->from_amount, Tx::EXCHANGE_OUT, -$usd),
                $leg('in', $m->toAccount, $m->to_amount, Tx::EXCHANGE_IN, $usd),
                $fee,
            ],
            // §43: el efectivo pasa de la agencia a la cuenta donde se guarda (no es ingreso nuevo). Lo descontado de lo que
            // se le debe (R16) sale con sus pagos de deuda
            CashMovement::AGENCY_SETTLEMENT => $m->from_amount > 0 ? [
                $leg('out', $m->fromAccount, -$m->from_amount, Tx::AGENCY_SETTLEMENT_OUT, -$usd),
                $leg('in', $m->toAccount, $m->to_amount, Tx::AGENCY_SETTLEMENT_IN, $usd),
            ] : [],
            default => [],
        }));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Pagos de deudas (§17, §102) y viajes de traslado (§13)

    /** Un pago de una deuda sale de la cuenta de donde se pagó (en una compensación, del efectivo de la agencia, R16). */
    public function syncPayablePayment(PayablePayment|int $payment): void
    {
        $id = $payment instanceof PayablePayment ? $payment->id : $payment;
        $p = PayablePayment::with(['account', 'payable.supplier:id,name', 'payable.agency:id,names'])->find($id);
        $reason = !$p ? 'El pago ya no existe.' : ($p->voided_at ? "Se anuló el pago: {$p->void_reason}" : null);
        $legs = [];
        if ($p && !$p->voided_at && $p->account && $this->counts($p->account, $p->paid_at->toDateString())) {
            [$amount, $rate, $usd] = $this->inAccountCurrency((float) $p->amount, $p->currency, $p->account->currency,
                $p->currency === 'VES' ? (float) $p->rate : self::binanceRate());
            $payable = $p->payable;
            $legs[] = [
                'leg' => 'main',
                'financial_account_id' => $p->account->id,
                'occurred_on' => $p->paid_at->toDateString(),
                'amount' => -$amount,
                'currency' => $p->account->currency,
                'rate' => $rate,
                'amount_usd' => -round((float) $p->amount_usd ?: $usd, 2),
                'kind' => Tx::PAYABLE_PAYMENT,
                'status' => Tx::CONFIRMED,
                'order_id' => null,
                'description' => mb_substr(($p->cash_movement_id ? 'Descontado de su efectivo: ' : 'Pago a ') . ($payable?->payeeName() ?? 'deuda')
                    . ($payable?->description ? " ({$payable->description})" : ''), 0, 255),
            ];
        }
        $this->apply('payable_payment', $id, $legs, $reason);
    }

    /** §13: lo que se pagó del viaje (mano de obra, combustible y otros). El desgaste no es dinero que sale (decisión nuestra). */
    public function syncTrip(InventoryTransferTrip|int $trip): void
    {
        $id = $trip instanceof InventoryTransferTrip ? $trip->id : $trip;
        $t = InventoryTransferTrip::with('account')->find($id);
        $reason = !$t ? 'El viaje ya no existe.' : ($t->voided_at ? "Se anuló el viaje: {$t->void_reason}" : null);
        $legs = [];
        $paid = $t ? round((float) $t->labor + (float) $t->fuel + (float) $t->other, 2) : 0.0;
        if ($t && !$t->voided_at && $t->account && $paid > 0 && $this->counts($t->account, $t->trip_date->toDateString())) {
            $vesRate = $t->currency === 'VES' ? (float) $t->rate : self::binanceRate();
            [$amount, $rate, $usd] = $this->inAccountCurrency($paid, $t->currency, $t->account->currency, $vesRate);
            $legs[] = [
                'leg' => 'main',
                'financial_account_id' => $t->account->id,
                'occurred_on' => $t->trip_date->toDateString(),
                'amount' => -$amount,
                'currency' => $t->account->currency,
                'rate' => $rate,
                'amount_usd' => -$usd,
                'kind' => Tx::TRIP_PAYMENT,
                'status' => Tx::CONFIRMED,
                'order_id' => null,
                'description' => mb_substr('Viaje de traslado' . ($t->description ? ": {$t->description}" : ''), 0, 255),
            ];
        }
        $this->apply('trip', $id, $legs, $reason);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Gastos (§28), reembolsos (§77) y cobros (§45)

    /** §28: el gasto manual pagado sale de su cuenta el día del gasto. Los de un viaje o de una comisión salen con su origen. */
    public function syncExpense(Expense|int $expense): void
    {
        $id = $expense instanceof Expense ? $expense->id : $expense;
        $e = Expense::with('account')->find($id);
        $reason = !$e ? 'El gasto ya no existe.' : ($e->voided_at ? "Se anuló el gasto: {$e->void_reason}" : null);
        $legs = [];
        if ($e && !$e->voided_at && !$e->inventory_trip_id && !$e->cash_movement_id && $e->account && $this->counts($e->account, $e->expense_date->toDateString())) {
            $legs[] = $this->outLeg($e->account, $e->expense_date->toDateString(), (float) $e->amount, $e->currency, (float) $e->rate, (float) $e->amount_usd,
                Tx::EXPENSE_PAYMENT, "Gasto: {$e->concept}");
        }
        $this->apply('expense', $id, $legs, $reason ?? 'El gasto ya no dice de qué cuenta se pagó.');
    }

    /** §28: el pago de un vencimiento de un gasto recurrente sale el día que se pagó. */
    public function syncRecurringPayment(RecurringExpensePayment|int $payment): void
    {
        $id = $payment instanceof RecurringExpensePayment ? $payment->id : $payment;
        $p = RecurringExpensePayment::with(['account', 'recurring:id,name'])->find($id);
        $reason = !$p ? 'El pago ya no existe.' : ($p->voided_at ? "Se anuló el pago: {$p->void_reason}" : null);
        $legs = [];
        if ($p && !$p->voided_at && $p->account && $this->counts($p->account, $p->paid_at->toDateString())) {
            $legs[] = $this->outLeg($p->account, $p->paid_at->toDateString(), $p->amount, $p->currency, $p->rate, $p->amount_usd,
                Tx::RECURRING_PAYMENT, ($p->recurring?->name ?? 'Gasto recurrente') . ' (vence el ' . $p->due_date->format('d/m') . ')');
        }
        $this->apply('recurring_payment', $id, $legs, $reason);
    }

    /**
     * §77: el reembolso sale de la cuenta que dijo el Admin o, si no, de la de su método (pago móvil → Banesco…). En bolívares,
     * con el monto que se escribió o, si no, el USD a la tasa del pago de la orden (aproximado).
     */
    public function syncRefund(OrderRefund|int $refund): void
    {
        $id = $refund instanceof OrderRefund ? $refund->id : $refund;
        $r = OrderRefund::with(['order:id,name', 'order.payments'])->find($id);
        $legs = [];
        $account = $r ? ($r->financial_account_id ? FinancialAccount::find($r->financial_account_id)
            : (($kind = OrderRefund::METHOD_KIND[$r->method ?? ''] ?? null) ? $this->accountOfMethod($kind) : null)) : null;
        if ($r && $account && $r->refunded_at && $this->counts($account, $r->refunded_at->toDateString())) {
            $usd = (float) $r->amount_usd;
            if ($r->amount !== null && $r->currency) {
                [$amount, $rate] = [(float) $r->amount, (float) ($r->rate ?: 1)];
                $currency = $r->currency;
            } elseif ($account->currency === 'VES') {
                $rate = (float) ($r->order?->payments->filter(fn ($p) => in_array($p->method, ['PAGOMOVIL', 'TRANSFERENCIA_BANCARIA_BOLIVARES'], true))
                    ->firstWhere('rate', '>', 0)?->rate ?: self::binanceRate());
                [$amount, $currency] = [round($usd * $rate, 2), 'VES'];
            } else {
                [$amount, $rate, $currency] = [$usd, 1.0, $account->currency];
            }
            $legs[] = $this->outLeg($account, $r->refunded_at->toDateString(), $amount, $currency, $rate, $usd, Tx::CUSTOMER_REFUND,
                'Reembolso de ' . ($r->order?->name ?? "la orden {$r->order_id}") . ($r->method ? " por {$r->method}" : ''), $r->order_id);
        }
        $this->apply('refund', $id, $legs, !$r ? 'El reembolso ya no existe.' : 'El reembolso ya no dice de qué cuenta salió.');
    }

    /** §45: un cobro entra a su cuenta el día que se cobró. */
    public function syncReceivableCollection(ReceivableCollection|int $collection): void
    {
        $id = $collection instanceof ReceivableCollection ? $collection->id : $collection;
        $c = ReceivableCollection::with(['account', 'receivable:id,debtor_name,description'])->find($id);
        $reason = !$c ? 'El cobro ya no existe.' : ($c->voided_at ? "Se anuló el cobro: {$c->void_reason}" : null);
        $legs = [];
        if ($c && !$c->voided_at && $c->account && $this->counts($c->account, $c->collected_at->toDateString())) {
            $leg = $this->outLeg($c->account, $c->collected_at->toDateString(), $c->amount, $c->currency, $c->rate, $c->amount_usd, Tx::RECEIVABLE_COLLECTION,
                'Cobro a ' . ($c->receivable?->debtor_name ?? '') . ($c->receivable?->description ? " ({$c->receivable->description})" : ''));
            $leg['amount'] = -$leg['amount'];
            $leg['amount_usd'] = -$leg['amount_usd'];
            $legs[] = $leg;
        }
        $this->apply('receivable_collection', $id, $legs, $reason);
    }

    /** Un movimiento que sale de una cuenta, en su moneda (USD y USDT valen igual; los Bs con su tasa). */
    private function outLeg(FinancialAccount $account, string $date, float $amount, string $currency, float $rate, float $usd, string $kind,
        string $description, ?int $orderId = null): array
    {
        [$amount, $legRate, $legUsd] = $this->inAccountCurrency($amount, $currency, $account->currency, $currency === 'VES' ? $rate : self::binanceRate());

        return [
            'leg' => 'main',
            'financial_account_id' => $account->id,
            'occurred_on' => $date,
            'amount' => -$amount,
            'currency' => $account->currency,
            'rate' => $legRate,
            'amount_usd' => -round($usd ?: $legUsd, 2),
            'kind' => $kind,
            'status' => Tx::CONFIRMED,
            'order_id' => $orderId,
            'description' => mb_substr($description, 0, 255),
        ];
    }

    /**
     * El pago sale de una cuenta de la caja en su misma moneda (USD y USDT valen igual, §33), después de su saldo inicial.
     * Desde el arranque de la caja la cuenta es obligatoria: si no, el dinero se movió sin que la caja lo sepa.
     */
    public static function assertAccount(?int $accountId, string $currency, string $date, string $field = 'financial_account_id'): void
    {
        if (!$accountId) {
            if ($date > self::startDate()) {
                throw \Illuminate\Validation\ValidationException::withMessages([$field => 'Elige la cuenta de la caja.']);
            }
            return;
        }
        $account = FinancialAccount::find($accountId);
        $family = fn (string $c) => in_array($c, ['USD', 'USDT'], true) ? 'USD' : $c;
        if (!$account || !$account->is_active || $account->isAgencyCash()) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => 'Esa cuenta no existe, está inactiva o es el efectivo de una agencia.']);
        }
        if ($family($account->currency) !== $family($currency)) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => "La cuenta {$account->name} es en {$account->currency}: el monto tiene que ir en esa moneda."]);
        }
        if ($date <= $account->opening_date->toDateString()) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => "La fecha tiene que ser después del saldo inicial de {$account->name}."]);
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Todo de nuevo (cada noche, y al desplegar)

    /** @return array{orders: int, items: int} */
    public function resyncAll(): array
    {
        $since = $this->earliestOpening();
        $orders = DB::table('orders')->where('status_id', $this->deliveredId())->where('delivered_at', '>', $since)->pluck('id')
            ->merge(Tx::whereNull('voided_at')->where('source_type', 'order')->pluck('source_id'))->unique()->values()->all();
        $this->syncOrders($orders);

        $items = ReconciliationItem::whereHas('day', fn ($q) => $q->where('date', '>=', $since))->pluck('id')
            ->merge(Tx::whereNull('voided_at')->where('source_type', 'reconciliation_item')->pluck('source_id'))->unique()->values();
        $items->each(fn ($id) => $this->syncReconciliationItem($id, !ReconciliationItem::whereKey($id)->exists()));

        $movements = CashMovement::pluck('id');
        $movements->each(fn ($id) => $this->syncCashMovement($id));
        $payments = PayablePayment::where('paid_at', '>', $since)->pluck('id')
            ->merge(Tx::whereNull('voided_at')->where('source_type', 'payable_payment')->pluck('source_id'))->unique();
        $payments->each(fn ($id) => $this->syncPayablePayment($id));
        $trips = InventoryTransferTrip::where('trip_date', '>', $since)->pluck('id')
            ->merge(Tx::whereNull('voided_at')->where('source_type', 'trip')->pluck('source_id'))->unique();
        $trips->each(fn ($id) => $this->syncTrip($id));
        $others = 0;
        foreach ([
            'expense' => [Expense::class, 'expense_date', 'syncExpense'],
            'recurring_payment' => [RecurringExpensePayment::class, 'paid_at', 'syncRecurringPayment'],
            'refund' => [OrderRefund::class, 'refunded_at', 'syncRefund'],
            'receivable_collection' => [ReceivableCollection::class, 'collected_at', 'syncReceivableCollection'],
        ] as $type => [$class, $dateColumn, $method]) {
            $ids = $class::where($dateColumn, '>', $since)->pluck('id')
                ->merge(Tx::whereNull('voided_at')->where('source_type', $type)->pluck('source_id'))->unique();
            $ids->each(fn ($id) => $this->{$method}($id));
            $others += $ids->count();
        }

        return ['orders' => count($orders), 'items' => $items->count(), 'movements' => $movements->count() + $payments->count() + $trips->count() + $others];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Saldos (§34: cuánto hay y dónde)

    /**
     * Cada cuenta con su saldo: el inicial más sus movimientos que cuentan hasta $date (incluido).
     * @return Collection<int, array>
     */
    public function balances(?string $date = null): Collection
    {
        // Solo lo de después del saldo inicial de cada cuenta (lo de antes ya está en ese saldo)
        $sums = Tx::join('financial_accounts as fa', 'fa.id', '=', 'financial_transactions.financial_account_id')
            ->whereNull('financial_transactions.voided_at')->whereColumn('financial_transactions.occurred_on', '>', 'fa.opening_date')
            ->when($date, fn ($q) => $q->where('financial_transactions.occurred_on', '<=', $date))
            ->groupBy('financial_account_id')
            ->selectRaw('financial_account_id,
                SUM(CASE WHEN status IN (?, ?) THEN amount ELSE 0 END) AS amount,
                SUM(CASE WHEN status = ? THEN amount ELSE 0 END) AS pending,
                SUM(CASE WHEN status = ? THEN amount ELSE 0 END) AS not_found,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS not_found_count,
                COUNT(*) AS movements', [Tx::CONFIRMED, Tx::PENDING, Tx::PENDING, Tx::NOT_FOUND, Tx::NOT_FOUND])
            ->get()->keyBy('financial_account_id');

        return FinancialAccount::with(['agency:id,names', 'companyAccounts:id,name,method,currency,is_active,financial_account_id'])
            ->orderByRaw("kind = 'agency_cash'")->orderBy('name')->get()
            ->map(function (FinancialAccount $a) use ($sums) {
                $s = $sums->get($a->id);
                $balance = round((float) ($a->opening_balance ?? 0) + (float) ($s->amount ?? 0), 2);

                return [
                    'id' => $a->id,
                    'name' => $a->name,
                    'kind' => $a->kind,
                    'kind_label' => FinancialAccount::KINDS[$a->kind] ?? $a->kind,
                    'currency' => $a->currency,
                    'agency_id' => $a->agency_id,
                    'opening_balance' => $a->opening_balance,
                    'opening_date' => $a->opening_date?->toDateString(),
                    'is_active' => $a->is_active,
                    'notes' => $a->notes,
                    'movements' => (int) ($s->movements ?? 0),
                    'balance' => $balance,
                    'balance_usd' => self::toUsdNow($balance, $a->currency),
                    'approximate' => in_array($a->currency, ['VES', 'EUR'], true),
                    'pending' => round((float) ($s->pending ?? 0), 2),
                    'not_found' => round((float) ($s->not_found ?? 0), 2),
                    'not_found_count' => (int) ($s->not_found_count ?? 0),
                    'methods' => $a->companyAccounts->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'method' => $c->method])->values(),
                ];
            });
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Cuentas

    /** §42: "Efectivo pendiente — Agencia X", una por agencia y moneda. Sin agencia: los repartidores propios. */
    public function agencyCashAccount(?int $agencyId, string $currency): FinancialAccount
    {
        $key = ($agencyId ?? 0) . "|{$currency}";
        if (isset($this->agencyAccounts[$key])) {
            return $this->agencyAccounts[$key];
        }
        $account = FinancialAccount::where('kind', FinancialAccount::AGENCY_CASH)->where('currency', $currency)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId), fn ($q) => $q->whereNull('agency_id'))->first();
        if (!$account) {
            $name = $agencyId ? (User::whereKey($agencyId)->value('names') ?? "agencia {$agencyId}") : 'repartidores propios';
            $account = FinancialAccount::create([
                'name' => "Efectivo pendiente — {$name} (" . self::CURRENCY_LABEL[$currency] . ')',
                'kind' => FinancialAccount::AGENCY_CASH,
                'currency' => $currency,
                'agency_id' => $agencyId,
                'opening_date' => self::startDate(),
                'is_active' => true,
            ]);
        }

        return $this->agencyAccounts[$key] = $account;
    }

    private function accountOfCompanyAccount(?int $companyAccountId): ?FinancialAccount
    {
        if (!$companyAccountId) {
            return null;
        }
        if (!array_key_exists($companyAccountId, $this->byCompanyAccount)) {
            $id = CompanyAccount::whereKey($companyAccountId)->value('financial_account_id');
            $this->byCompanyAccount[$companyAccountId] = $id ? FinancialAccount::find($id) : null;
        }

        return $this->byCompanyAccount[$companyAccountId];
    }

    /** La cuenta de la caja de un método del Módulo 1: la de su única cuenta activa (o la que comparten todas). */
    private function accountOfMethod(string $kind): ?FinancialAccount
    {
        if (!array_key_exists($kind, $this->byMethod)) {
            $ids = CompanyAccount::where('method', $kind)->where('is_active', true)->pluck('financial_account_id')->unique();
            $this->byMethod[$kind] = $ids->count() === 1 && $ids->first() ? FinancialAccount::find($ids->first()) : null;
        }

        return $this->byMethod[$kind];
    }

    /** Lo de antes del saldo inicial de la cuenta ya está en ese saldo (R14). */
    private function counts(FinancialAccount $account, string $date): bool
    {
        return $date > $account->opening_date->toDateString();
    }

    private function earliestOpening(): string
    {
        return $this->earliest ??= (string) (FinancialAccount::min('opening_date') ?? self::startDate());
    }

    /** [monto en la moneda de la cuenta, tasa, USD]. USD y USDT valen 1 a 1 (§33); los Bs van con su tasa. */
    private function inAccountCurrency(float $amount, string $from, string $to, float $vesRate): array
    {
        $dollars = ['USD', 'USDT'];
        $usd = $from === 'VES' ? ($vesRate > 0 ? $amount / $vesRate : 0) : $amount;
        if ($from === $to || (in_array($from, $dollars, true) && in_array($to, $dollars, true))) {
            return [$amount, $to === 'VES' ? round($vesRate, 4) : 1.0, round($usd, 2)];
        }
        if ($to === 'VES') {
            return [round($usd * $vesRate, 2), round($vesRate, 4), round($usd, 2)];
        }

        return [round($usd, 2), 1.0, round($usd, 2)];
    }

    private function family(?string $kind): ?string
    {
        return in_array($kind, self::VES_KINDS, true) ? 'bs' : $kind;
    }

    private function deliveredId(): ?int
    {
        return $this->deliveredId ??= Status::where('description', OrderStatus::ENTREGADO)->value('id');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Cuadrar los movimientos de un origen con lo que debería tener

    private function apply(string $type, int $id, array $legs, ?string $reason = null): void
    {
        $this->applyMany($type, [$id => $legs], [$id => $reason]);
    }

    /**
     * @param array<int, array> $legsBySource lo que debería tener cada origen
     * @param array<int, ?string> $reasons el motivo de anular lo que sobra
     */
    private function applyMany(string $type, array $legsBySource, array $reasons = []): void
    {
        if ($legsBySource === []) {
            return;
        }
        $existing = Tx::where('source_type', $type)->whereIn('source_id', array_keys($legsBySource))->whereNull('voided_at')->get()->groupBy('source_id');
        foreach ($legsBySource as $id => $legs) {
            $current = ($existing->get($id) ?? collect())->keyBy('leg');
            if ($legs === [] && $current->isEmpty()) {
                continue;
            }
            DB::transaction(function () use ($type, $id, $legs, $current, $reasons) {
                $wanted = collect($legs)->keyBy('leg');
                foreach ($current as $leg => $tx) {
                    $w = $wanted->get($leg);
                    if ($w && $this->sameMoney($tx, $w)) {
                        $tx->fill(array_intersect_key($w, array_flip(['status', 'description', 'order_id', 'kind'])));
                        if ($tx->isDirty()) {
                            $tx->save();
                        }
                        $wanted->forget($leg);
                        continue;
                    }
                    $tx->forceFill([
                        'voided_at' => now(),
                        'void_reason' => $w ? 'Cambió el origen: ' . $this->difference($tx, $w) : ($reasons[$id] ?? 'El origen ya no da este movimiento.'),
                    ])->save();
                }
                foreach ($wanted as $w) {
                    Tx::create($w + ['source_type' => $type, 'source_id' => $id]);
                }
            });
        }
    }

    private function sameMoney(Tx $tx, array $w): bool
    {
        return (int) $tx->financial_account_id === (int) $w['financial_account_id']
            && $tx->occurred_on->toDateString() === $w['occurred_on']
            && abs($tx->amount - (float) $w['amount']) < 0.005
            && $tx->currency === $w['currency']
            && abs($tx->rate - (float) $w['rate']) < 0.00005
            && abs($tx->amount_usd - (float) $w['amount_usd']) < 0.005;
    }

    private function difference(Tx $tx, array $w): string
    {
        $parts = [];
        if ((int) $tx->financial_account_id !== (int) $w['financial_account_id']) {
            $parts[] = 'la cuenta';
        }
        if ($tx->occurred_on->toDateString() !== $w['occurred_on']) {
            $parts[] = "la fecha ({$w['occurred_on']})";
        }
        if (abs($tx->amount - (float) $w['amount']) >= 0.005 || $tx->currency !== $w['currency']) {
            $parts[] = 'el monto (' . number_format((float) $w['amount'], 2, ',', '.') . " {$w['currency']})";
        } elseif (abs($tx->rate - (float) $w['rate']) >= 0.00005 || abs($tx->amount_usd - (float) $w['amount_usd']) >= 0.005) {
            $parts[] = 'la tasa';
        }

        return implode(', ', $parts) ?: 'el detalle';
    }
}
