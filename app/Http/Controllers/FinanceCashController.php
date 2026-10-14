<?php

namespace App\Http\Controllers;

use App\Models\CashMovement;
use App\Models\CompanyAccount;
use App\Models\FinancialAccount;
use App\Models\FinancialAuditLog;
use App\Models\FinancialTransaction as Tx;
use App\Models\ReconciliationItem;
use App\Services\Finance\CashLedger;
use App\Services\Finance\CashMovements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Finanzas › Caja (Módulo 4 de Fran, entrega 3; §34 a §36, §42, §75, §76, R14). Solo el Admin (§81).
 * - Las cuentas con su saldo: el inicial (§36) más sus movimientos (§34: cuánto dinero existe y dónde está).
 * - Los movimientos, con su origen (§49: cada cifra se puede explicar).
 * - Las cuentas del Módulo 1 (una por método) y la cuenta de la caja a la que entra lo que llega por ellas (R14).
 */
class FinanceCashController extends Controller
{
    public function index(CashLedger $ledger): JsonResponse
    {
        $start = CashLedger::startDate();
        $accounts = $ledger->balances();
        $active = $accounts->where('is_active', true);
        $own = $active->where('kind', '!=', FinancialAccount::AGENCY_CASH);
        $usd = fn ($rows, string $field) => round($rows->sum(fn ($a) => CashLedger::toUsdNow((float) $a[$field], $a['currency']) ?? 0), 2);

        $unlinked = CompanyAccount::where('is_active', true)->whereNotNull('method')->whereNull('financial_account_id')->get(['id', 'name']);
        $withoutAccount = ReconciliationItem::whereNull('company_account_id')->where('status', '!=', ReconciliationItem::NOT_RECEIVED)
            ->whereHas('day', fn ($q) => $q->where('date', '>', $start))->count();

        return response()->json([
            'start_date' => $start,
            'binance_rate' => CashLedger::binanceRate(),
            'accounts' => $accounts->values(),
            'totals' => [
                'available_usd' => $usd($own, 'balance'),
                'agencies_usd' => $usd($active->where('kind', FinancialAccount::AGENCY_CASH), 'balance'),
                'pending_usd' => $usd($own, 'pending'),
                'not_found_usd' => $usd($accounts, 'not_found'),
                'not_found_count' => $accounts->sum('not_found_count'),
                'without_opening' => $active->whereNull('opening_balance')->count(),
            ],
            'company_accounts' => CompanyAccount::with('statementSource:id,name')->orderBy('name')->get()->map(fn (CompanyAccount $c) => [
                'id' => $c->id, 'name' => $c->name, 'method' => $c->method, 'method_label' => CompanyAccount::methods()[$c->method] ?? $c->method,
                'currency' => $c->currency, 'is_active' => $c->is_active, 'statement_source' => $c->statement_source_name,
                'financial_account_id' => $c->financial_account_id,
            ])->values(),
            'warnings' => [
                'unlinked' => $unlinked->pluck('name')->values(),
                'items_without_account' => $withoutAccount,
            ],
            'kinds' => array_diff_key(FinancialAccount::KINDS, [FinancialAccount::AGENCY_CASH => 1]),
            'currencies' => FinancialAccount::CURRENCIES,
            'transaction_kinds' => Tx::KIND_LABEL,
            'statuses' => Tx::STATUS_LABEL,
            'movement_kinds' => CashMovement::KIND_LABEL,
        ]);
    }

    /** Los movimientos, del más nuevo al más viejo, con lo que entró y salió en el filtro. */
    public function transactions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => 'nullable|integer|exists:financial_accounts,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'kind' => ['nullable', Rule::in(array_keys(Tx::KIND_LABEL))],
            'voided' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
        ]);
        $query = Tx::query()
            ->when($data['account_id'] ?? null, fn ($q, $id) => $q->where('financial_account_id', $id))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('occurred_on', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('occurred_on', '<=', $d))
            ->when($data['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when(!($data['voided'] ?? false), fn ($q) => $q->whereNull('voided_at'));

        $sum = (clone $query)->whereNull('voided_at')->whereIn('status', Tx::COUNTED)
            ->selectRaw('SUM(CASE WHEN amount_usd > 0 THEN amount_usd ELSE 0 END) AS inflow, SUM(CASE WHEN amount_usd < 0 THEN amount_usd ELSE 0 END) AS outflow')
            ->first();
        $page = $query->with(['account:id,name,currency', 'order:id,name'])->orderByDesc('occurred_on')->orderByDesc('id')->paginate(100);
        $movements = CashMovement::whereIn('id', collect($page->items())->where('source_type', 'cash_movement')->pluck('source_id'))->get()->keyBy('id');

        return response()->json([
            'inflow_usd' => round((float) ($sum->inflow ?? 0), 2),
            'outflow_usd' => round((float) ($sum->outflow ?? 0), 2),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'transactions' => collect($page->items())->map(fn (Tx $t) => [
                'id' => $t->id,
                'date' => $t->occurred_on->toDateString(),
                'account_id' => $t->financial_account_id,
                'account' => $t->account?->name,
                'kind' => $t->kind,
                'kind_label' => Tx::KIND_LABEL[$t->kind] ?? $t->kind,
                'description' => $t->description,
                'amount' => $t->amount,
                'currency' => $t->currency,
                'rate' => $t->rate,
                'amount_usd' => $t->amount_usd,
                'status' => $t->status,
                'status_label' => Tx::STATUS_LABEL[$t->status] ?? $t->status,
                'order_id' => $t->order_id,
                'order_name' => $t->order?->name,
                'source_type' => $t->source_type,
                'source_id' => $t->source_id,
                'voided_at' => $t->voided_at?->format('Y-m-d H:i'),
                'void_reason' => $t->void_reason,
                'created_at' => $t->created_at?->format('Y-m-d H:i'),
                'movement' => ($m = $t->source_type === 'cash_movement' ? $movements->get($t->source_id) : null) ? [
                    'id' => $m->id, 'kind' => $m->kind, 'kind_label' => CashMovement::KIND_LABEL[$m->kind] ?? $m->kind, 'reference' => $m->reference,
                    'has_receipt' => (bool) $m->receipt_path, 'voided' => $m->voided_at !== null,
                ] : null,
            ])->values(),
        ]);
    }

    /** §35: una cuenta nueva (Mercantil, efectivo…), con su saldo empresarial (§36). */
    public function storeAccount(Request $request): JsonResponse
    {
        $start = CashLedger::startDate();
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:financial_accounts,name',
            'kind' => ['required', Rule::in(array_keys(array_diff_key(FinancialAccount::KINDS, [FinancialAccount::AGENCY_CASH => 1])))],
            'currency' => ['required', Rule::in(FinancialAccount::CURRENCIES)],
            'opening_balance' => 'nullable|numeric|min:-9999999999|max:9999999999',
            'opening_date' => "nullable|date|after_or_equal:{$start}|before_or_equal:today",
            'notes' => 'nullable|string|max:500',
        ], [
            'name.unique' => 'Ya hay una cuenta con ese nombre.',
            'opening_date.after_or_equal' => 'La caja arranca el ' . date('d/m/Y', strtotime($start)) . ': el saldo inicial no puede ser de antes.',
        ]);
        $data['opening_date'] = $data['opening_date'] ?? $start;
        $account = FinancialAccount::create($data + ['is_active' => true, 'created_by' => Auth::id()]);
        FinancialAuditLog::record('cash_account_created', 'financial_account', $account->id, null, $account->snapshot());

        return response()->json(['status' => true, 'message' => 'Cuenta creada.', 'account_id' => $account->id]);
    }

    /** Nombre, notas, activa y saldo inicial. Cambiar un saldo inicial ya puesto pide el motivo (§82). */
    public function updateAccount(Request $request, FinancialAccount $account): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('financial_accounts', 'name')->ignore($account->id)],
            'is_active' => 'required|boolean',
            'opening_balance' => 'nullable|numeric|min:-9999999999|max:9999999999',
            'notes' => 'nullable|string|max:500',
            'reason' => 'nullable|string|max:500',
        ], ['name.unique' => 'Ya hay una cuenta con ese nombre.']);
        $newOpening = $data['opening_balance'] ?? null;
        if ($account->opening_balance !== null && !$this->sameAmount($account->opening_balance, $newOpening) && trim((string) ($data['reason'] ?? '')) === '') {
            return response()->json(['status' => false, 'message' => 'Escribe el motivo: el saldo inicial ya estaba puesto.'], 422);
        }
        $before = $account->snapshot();
        $account->update(['name' => $data['name'], 'is_active' => $data['is_active'], 'opening_balance' => $newOpening, 'notes' => $data['notes'] ?? null]);
        FinancialAuditLog::record('cash_account_updated', 'financial_account', $account->id, $before, $account->snapshot(), $data['reason'] ?? null);

        return response()->json(['status' => true, 'message' => 'Cuenta guardada.']);
    }

    /** §36 y R14: los saldos del cierre del 14/10, todos de una vez. */
    public function openingBalances(Request $request): JsonResponse
    {
        $data = $request->validate([
            'balances' => 'required|array|min:1',
            'balances.*.id' => 'required|integer|distinct|exists:financial_accounts,id',
            'balances.*.opening_balance' => 'nullable|numeric|min:-9999999999|max:9999999999',
            'reason' => 'nullable|string|max:500',
        ]);
        $accounts = FinancialAccount::whereIn('id', array_column($data['balances'], 'id'))->get()->keyBy('id');
        $changes = collect($data['balances'])->filter(fn ($b) => !$this->sameAmount($accounts[$b['id']]->opening_balance, $b['opening_balance'] ?? null));
        if ($changes->contains(fn ($b) => $accounts[$b['id']]->opening_balance !== null) && trim((string) ($data['reason'] ?? '')) === '') {
            return response()->json(['status' => false, 'message' => 'Escribe el motivo: hay saldos iniciales que ya estaban puestos.'], 422);
        }
        DB::transaction(function () use ($changes, $accounts, $data) {
            foreach ($changes as $b) {
                $account = $accounts[$b['id']];
                $before = $account->snapshot();
                $account->update(['opening_balance' => $b['opening_balance'] ?? null]);
                FinancialAuditLog::record('cash_opening_balance', 'financial_account', $account->id, $before, $account->snapshot(), $data['reason'] ?? null);
            }
        });

        return response()->json(['status' => true, 'message' => $changes->isEmpty() ? 'No había cambios.' : 'Saldos iniciales guardados.']);
    }

    /** R14 y §75: a qué cuenta de la caja entra lo que llega por un método del Módulo 1. Sus pagos se mueven con ella. */
    public function linkCompanyAccount(Request $request, CompanyAccount $companyAccount): JsonResponse
    {
        $data = $request->validate(['financial_account_id' => 'nullable|integer|exists:financial_accounts,id']);
        $target = isset($data['financial_account_id']) ? FinancialAccount::find($data['financial_account_id']) : null;
        if ($target && $target->isAgencyCash()) {
            return response()->json(['status' => false, 'message' => 'Los pagos digitales no van al efectivo de una agencia.'], 422);
        }
        $before = ['financial_account_id' => $companyAccount->financial_account_id];
        $companyAccount->update(['financial_account_id' => $target?->id]);
        FinancialAuditLog::record('cash_account_link', 'financial_account', $target?->id ?? $before['financial_account_id'], $before,
            ['company_account' => $companyAccount->name, 'financial_account_id' => $target?->id]);

        return response()->json(['status' => true, 'message' => $target ? "Lo que llega por {$companyAccount->name} entra a {$target->name}." : 'Quedó sin cuenta de la caja.']);
    }

    /** §37 a §41 y §83: aporte, retiro, transferencia (con comisión), cambio de moneda o ajuste. Comprobante opcional (§79). */
    public function storeMovement(Request $request, CashMovements $movements): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(CashMovement::KIND_LABEL))],
            'occurred_on' => 'required|date|before_or_equal:today',
            'from_account_id' => 'nullable|integer|exists:financial_accounts,id',
            'from_amount' => 'nullable|numeric|min:0.01|max:9999999999',
            'to_account_id' => 'nullable|integer|exists:financial_accounts,id',
            'to_amount' => 'nullable|numeric|min:0.01|max:9999999999',
            'rate' => 'nullable|numeric|min:0.0001|max:99999999',
            'fee' => 'nullable|numeric|min:0|max:9999999999',
            'reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:500',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ], ['occurred_on.before_or_equal' => 'La fecha no puede ser futura.']);
        try {
            $movement = $movements->create($data, $request->file('receipt'), Auth::id());
        } catch (RuntimeException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
        $done = [
            CashMovement::OWNER_CONTRIBUTION => 'Aporte registrado.', CashMovement::OWNER_WITHDRAWAL => 'Retiro registrado.',
            CashMovement::INTERNAL_TRANSFER => 'Transferencia registrada.', CashMovement::EXCHANGE => 'Cambio de moneda registrado.',
            CashMovement::ADJUSTMENT => 'Ajuste registrado.',
        ][$movement->kind];

        return response()->json(['status' => true, 'message' => $movement->fee > 0
            ? "{$done} La comisión quedó como gasto en \"" . CashMovements::FEE_CATEGORY . '".' : $done, 'movement_id' => $movement->id]);
    }

    /** §83: anular con el motivo. Sus movimientos de cuenta y su comisión dejan de contar, y quedan a la vista. */
    public function voidMovement(Request $request, CashMovement $movement, CashMovements $movements): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500'], ['reason.required' => 'Escribe por qué se anula.']);
        try {
            $movements->void($movement, trim($data['reason']), Auth::id());
        } catch (RuntimeException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => true, 'message' => 'Movimiento anulado: ya no cuenta, pero queda en el historial.']);
    }

    public function movementReceipt(CashMovement $movement)
    {
        abort_unless($movement->receipt_path && Storage::disk('local')->exists($movement->receipt_path), 404, 'El comprobante ya no está.');

        return Storage::disk('local')->download($movement->receipt_path, "movimiento-{$movement->id}." . pathinfo($movement->receipt_path, PATHINFO_EXTENSION));
    }

    private function sameAmount(?float $a, $b): bool
    {
        if ($a === null || $b === null || $b === '') {
            return $a === null && ($b === null || $b === '');
        }

        return abs($a - (float) $b) < 0.005;
    }
}
