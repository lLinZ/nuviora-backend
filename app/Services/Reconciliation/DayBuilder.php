<?php

namespace App\Services\Reconciliation;

use App\Models\BankStatementRow;
use App\Models\CompanyAccount;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\ReceiptCheck;
use App\Models\ReconciliationDay;
use App\Models\ReconciliationItem;
use App\Services\Payments\ReceiptChecker;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Arma la conciliación de un día (documento de Fran del 2026-10-06, Módulo 1):
 * - §12: los pagos digitales registrados ese día, que son los comprobantes digitales subidos ese día que el sistema
 *   aceptó (cuadran, cuadran con advertencia o los aprobó el administrador). El efectivo no entra (§22) y un
 *   comprobante repetido en la misma orden cuenta una vez.
 * - §12 y §13: de ellos sale qué extractos hacen falta: los de las cuentas que recibieron pagos, y ninguno más.
 * - §14: cada fecha es una conciliación aparte, que queda hasta completarse.
 * - §3 y §11: cada pago guarda una copia de sus datos, que queda aunque después se borre el comprobante.
 */
class DayBuilder
{
    public const DIGITAL = ['pago_movil', 'transferencia', 'binance', 'zinli', 'zelle', 'paypal'];

    public function __construct(private StatementMatcher $matcher)
    {
    }

    /** Arma o pone al día la conciliación de esa fecha. Null si ese día no hubo pagos digitales. */
    public function build(CarbonInterface $date): ?ReconciliationDay
    {
        $date = Carbon::parse($date->toDateString());
        $receipts = PaymentReceipt::whereBetween('created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->whereHas('order')->with(['check', 'order.client', 'order.payments'])->orderBy('id')->get();
        $wanted = $this->paymentsOf($receipts);

        $day = ReconciliationDay::whereDate('date', $date)->first();
        if (!$day && !$wanted) {
            return null;
        }
        $day ??= ReconciliationDay::create(['date' => $date->toDateString(), 'status' => ReconciliationDay::PENDING]);

        $existing = $day->items()->get()->keyBy(fn (ReconciliationItem $i) => $this->key($i));
        foreach ($wanted as $key => $attributes) {
            $item = $existing->get($key);
            if (!$item) {
                $day->items()->create($attributes);
            } elseif ($item->status === ReconciliationItem::PENDING) {
                $item->update($attributes); // todavía no se buscó: vale la última lectura del comprobante
            }
        }
        // Lo que dejó de ser un pago aceptado (p. ej. ahora no cuadra) y todavía no se buscó, sale. Si se borró el
        // comprobante, el pago queda con sus datos (§11).
        foreach ($existing as $key => $item) {
            if (!isset($wanted[$key]) && $item->status === ReconciliationItem::PENDING
                && $item->payment_receipt_id && PaymentReceipt::whereKey($item->payment_receipt_id)->exists()) {
                $item->delete();
            }
        }

        if ($day->statements()->current()->exists()) {
            $this->matcher->matchDay($day);
        }
        $this->refreshStatus($day);

        return $day->fresh();
    }

    /**
     * Los extractos que hacen falta (§12 y §13): los de las cuentas que recibieron pagos ese día. Si de un pago no se
     * sabe la cuenta, los de todas las cuentas activas de su método. Con $open, solo los de pagos sin resolver: lo que el
     * administrador ya resolvió a mano no necesita extracto.
     * @return Collection<int, int> ids de statement_sources
     */
    public function neededSources(ReconciliationDay $day, bool $open = false): Collection
    {
        $query = $day->items();
        if ($open) {
            $query->whereNotIn('status', ReconciliationItem::RESOLVED);
        }
        $items = $query->get(['statement_source_id', 'method']);
        $unknownMethods = $items->whereNull('statement_source_id')->pluck('method')->unique();

        return $items->pluck('statement_source_id')->filter()
            ->merge($unknownMethods->isEmpty() ? [] : CompanyAccount::whereIn('method', $unknownMethods)->where('is_active', true)
                ->whereNotNull('statement_source_id')->pluck('statement_source_id'))
            ->map(fn ($id) => (int) $id)->unique()->values();
    }

    /**
     * Completada si todos los pagos están conciliados o resueltos a mano (§25); si no, pendiente mientras falte el
     * extracto de algún pago sin resolver, y en revisión cuando solo quedan incidencias (§24).
     */
    public function refreshStatus(ReconciliationDay $day): void
    {
        $uploaded = $day->statements()->current()->pluck('statement_source_id')->map(fn ($id) => (int) $id);
        $open = $day->items()->whereNotIn('status', ReconciliationItem::RESOLVED)->exists();
        $status = !$open ? ReconciliationDay::COMPLETED
            : ($this->neededSources($day, true)->diff($uploaded)->isNotEmpty() ? ReconciliationDay::PENDING : ReconciliationDay::REVIEW);
        $day->forceFill([
            'status' => $status,
            'completed_at' => $status === ReconciliationDay::COMPLETED ? ($day->completed_at ?? now()) : null,
        ])->save();
    }

    /** Los pagos digitales de los comprobantes subidos ese día, por clave (ver key()). */
    private function paymentsOf(Collection $receipts): array
    {
        $accounts = CompanyAccount::whereNotNull('method')->get();
        $out = [];
        foreach ($receipts->groupBy('order_id') as $orderReceipts) {
            $order = $orderReceipts->first()->order;
            foreach ($orderReceipts as $receipt) {
                if ($receipt->check && $this->accepted($receipt->check) && !$this->repeated($receipt->check)) {
                    $out["check:{$receipt->check->id}"] = $this->fromCheck($receipt, $receipt->check, $order, $accounts);
                }
            }

            // Un método digital de la orden que no tiene un comprobante digital aceptado, pero sí uno de ese día que no se
            // pudo leer o que el administrador aprobó a mano: el pago está registrado, aunque no haya datos para buscarlo
            $unread = $orderReceipts->first(fn (PaymentReceipt $r) => !$r->check
                || in_array($r->check->status, [ReceiptCheck::PENDING, ReceiptCheck::ERROR], true)
                || ($r->check->isApproved() && !in_array($r->check->kind, self::DIGITAL, true)));
            if (!$unread) {
                continue;
            }
            $covered = ReceiptCheck::where('order_id', $order->id)->get()->filter(fn ($c) => $this->accepted($c))
                ->map(fn ($c) => $this->family($c->kind))->unique();
            foreach ($order->payments->pluck('method')->unique() as $method) {
                $kind = ReceiptChecker::METHOD_KIND[$method] ?? null;
                if (!in_array($kind, self::DIGITAL, true) || $covered->contains($this->family($kind))) {
                    continue;
                }
                $covered->push($this->family($kind));
                $out["receipt:{$unread->id}:{$this->family($kind)}"] = $this->withoutData($unread, $kind, $order, $accounts);
            }
        }

        return $out;
    }

    /** Un comprobante digital que el sistema da por recibido: cuadra, cuadra con advertencia o lo aprobó el administrador. */
    private function accepted(ReceiptCheck $check): bool
    {
        return in_array($check->kind, self::DIGITAL, true)
            && (in_array($check->status, [ReceiptCheck::OK, ReceiptCheck::WARNING], true) || $check->isApproved());
    }

    /** El mismo comprobante otra vez en la orden (misma referencia): cuenta una sola vez. */
    private function repeated(ReceiptCheck $check): bool
    {
        return collect($check->issues ?? [])->contains(fn ($i) => ($i['code'] ?? null) === 'duplicate' && $i['level'] === 'warning');
    }

    private function fromCheck(PaymentReceipt $receipt, ReceiptCheck $check, Order $order, Collection $accounts): array
    {
        $account = $check->company_account_id ? $accounts->firstWhere('id', $check->company_account_id) : $this->onlyAccount($accounts, $check->kind);

        return [
            'receipt_check_id' => $check->id,
            'payment_receipt_id' => $receipt->id,
            'order_id' => $order->id,
            'order_name' => $order->name,
            'client_name' => $this->clientName($order),
            'method' => $check->kind,
            'currency' => $check->currency ?: $account?->currency,
            'amount' => $check->amount,
            'reference' => $check->reference,
            'reference_norm' => BankStatementRow::normalizeReference($check->reference),
            'paid_on' => $this->paidOn($check, $receipt),
            'company_account_id' => $account?->id,
            'statement_source_id' => $account?->statement_source_id,
        ];
    }

    private function withoutData(PaymentReceipt $receipt, string $kind, Order $order, Collection $accounts): array
    {
        $account = $this->onlyAccount($accounts, $kind);

        return [
            'receipt_check_id' => null,
            'payment_receipt_id' => $receipt->id,
            'order_id' => $order->id,
            'order_name' => $order->name,
            'client_name' => $this->clientName($order),
            'method' => $kind,
            'currency' => $account?->currency,
            'amount' => null,
            'reference' => null,
            'reference_norm' => null,
            'paid_on' => null,
            'company_account_id' => $account?->id,
            'statement_source_id' => $account?->statement_source_id,
            'match_note' => 'El comprobante no tiene datos leídos para buscarlo en el extracto.',
        ];
    }

    /** Si el método tiene una sola cuenta activa, el pago fue a esa (§13). */
    private function onlyAccount(Collection $accounts, string $kind): ?CompanyAccount
    {
        $active = $accounts->where('method', $kind)->where('is_active', true);

        return $active->count() === 1 ? $active->first() : null;
    }

    /** La fecha que dice el comprobante, si es razonable: hasta una semana antes de subirlo. */
    private function paidOn(ReceiptCheck $check, PaymentReceipt $receipt): ?string
    {
        $date = $check->extracted['fecha'] ?? null;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $uploaded = Carbon::parse($receipt->created_at)->startOfDay();
        $paid = Carbon::parse($date);

        return $paid->between($uploaded->copy()->subDays(7), $uploaded->copy()->addDay()) ? $date : null;
    }

    private function clientName(Order $order): ?string
    {
        $name = trim(($order->client->first_name ?? '') . ' ' . ($order->client->last_name ?? ''));

        return $name !== '' ? mb_substr($name, 0, 160) : null;
    }

    private function family(?string $kind): ?string
    {
        return in_array($kind, ['pago_movil', 'transferencia'], true) ? 'bs' : $kind;
    }

    private function key(ReconciliationItem $item): string
    {
        return $item->receipt_check_id ? "check:{$item->receipt_check_id}" : "receipt:{$item->payment_receipt_id}:{$this->family($item->method)}";
    }
}
