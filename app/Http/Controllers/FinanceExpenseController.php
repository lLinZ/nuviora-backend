<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\FinancialAccount;
use App\Models\RecurringExpensePayment;
use App\Services\Finance\OtherPayments;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAuditLog;
use App\Models\InventoryPurchase;
use App\Models\Product;
use App\Models\RecurringExpense;
use App\Models\RecurringExpenseVersion;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Finance\ExpenseLedger;
use App\Services\Finance\TransferTrips;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Finanzas › Gastos (Módulo 4 de Fran, §24 a §33). Solo el Admin (§81, por la ruta).
 * Nada se borra (§83): un gasto se corrige con motivo o se anula, y un recurrente cambia de monto con una versión nueva
 * desde una fecha (§27). Cada cambio queda en financial_audit_logs con el valor anterior, el nuevo y el motivo (§82).
 */
class FinanceExpenseController extends Controller
{
    private const MESSAGES = [
        'concept.required' => 'Escribe el concepto del gasto.',
        'name.required' => 'Escribe el nombre del gasto.',
        'expense_category_id.required' => 'Elige la categoría.',
        'amount.required' => 'Escribe el monto.',
        'amount.min' => 'El monto tiene que ser mayor que 0.',
        'rate.required_if' => 'En bolívares, escribe la tasa (bolívares por dólar) de este gasto.',
        'expense_date.before_or_equal' => 'La fecha del gasto no puede ser futura.',
        'reason.required' => 'Escribe el motivo del cambio: queda en el historial.',
        'receipt.mimes' => 'El comprobante tiene que ser una imagen o un PDF.',
        'receipt.max' => 'El comprobante no puede pasar de 10 MB.',
        'interval_days.required_if' => 'Escribe cada cuántos días se paga.',
        'payment_day.required_if' => 'Elige el día de pago.',
        'payment_date.required_if' => 'Elige la fecha de pago.',
        'valid_from.required' => 'Elige desde cuándo vale el monto.',
    ];

    public function options(): JsonResponse
    {
        return response()->json([
            'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name', 'is_active']),
            'products' => Product::orderBy('title')->get(['id', 'title']),
            'cities' => City::orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'agencies' => User::whereHas('role', fn ($q) => $q->where('description', 'Agencia'))->orderBy('names')->get(['id', 'names']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name', 'is_active']),
            'currencies' => Expense::CURRENCIES,
            // Compras y pagos a proveedores (entrega 2b): de qué cuenta salió el pago (opcional hasta la caja, entrega 3)
            // Las cuentas de la caja de donde sale un pago (entrega 3). El efectivo de las agencias no: se mueve con su liquidación
            'financial_accounts' => FinancialAccount::where('is_active', true)->where('kind', '!=', FinancialAccount::AGENCY_CASH)
                ->orderBy('name')->get(['id', 'name', 'currency', 'kind']),
            'cash_start_date' => \App\Services\Finance\CashLedger::startDate(),
            'payee_types' => OtherPayments::PAYEE_TYPES,
            'payment_types' => InventoryPurchase::PAYMENT_TYPES,
            // Viajes de traslado (entrega 2c, §15): la regla de desgaste vigente
            'vehicle_wear' => TransferTrips::wearRule() + ['label' => TransferTrips::wearLabel(TransferTrips::wearRule()['mode'], TransferTrips::wearRule()['value'])],
            'frequencies' => RecurringExpense::FREQUENCIES,
            'weekdays' => RecurringExpense::WEEKDAYS,
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80|unique:expense_categories,name'],
            ['name.required' => 'Escribe el nombre de la categoría.', 'name.unique' => 'Ya hay una categoría con ese nombre.']);
        $category = ExpenseCategory::create(['name' => trim($data['name'])]);
        FinancialAuditLog::record('expense_category_created', 'expense_category', $category->id, null, ['name' => $category->name]);

        return response()->json(['message' => "Categoría \"{$category->name}\" creada.", 'category' => $category->only(['id', 'name', 'is_active'])], 201);
    }

    /** Los gastos manuales de un período y el resumen con los recurrentes repartidos en esos días (§28). */
    public function index(Request $request, ExpenseLedger $ledger): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'category_id' => 'nullable|integer',
            'voided' => 'nullable|boolean',
            'q' => 'nullable|string|max:100',
        ]);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now())->startOfDay();

        $query = Expense::with(['category:id,name', 'product:id,title', 'city:id,name', 'warehouse:id,name', 'agency:id,names',
            'supplier:id,name', 'creator:id,names', 'voider:id,names'])
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('expense_date')->orderByDesc('id');
        if (!empty($data['category_id'])) {
            $query->where('expense_category_id', $data['category_id']);
        }
        if (!($data['voided'] ?? false)) {
            $query->whereNull('voided_at');
        }
        if ($q = trim((string) ($data['q'] ?? ''))) {
            $query->where(fn ($w) => $w->where('concept', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%"));
        }

        $period = $ledger->forRange($from, $to);
        $byCategory = collect($period['items'])->groupBy(fn ($i) => $i['category'] ?? 'Sin categoría')
            ->map(fn ($g, $name) => ['category' => $name, 'amount_usd' => round($g->sum('amount_usd'), 2)])
            ->sortByDesc('amount_usd')->values();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'expenses' => $query->limit(500)->get()->map->toPayload(),
            'summary' => [
                'total' => $period['total'],
                'manual' => round(collect($period['items'])->where('type', 'manual')->sum('amount_usd'), 2),
                'recurring' => round(collect($period['items'])->where('type', 'recurring')->sum('amount_usd'), 2),
                'general' => $period['general'],
                'assigned' => $period['assigned'],
                // §13: el transporte de inventario del período (los viajes). No suma arriba: va en la logística (§14)
                'trips' => $period['trips'],
                'by_category' => $byCategory,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->expenseRules(), self::MESSAGES);
        $expense = DB::transaction(function () use ($request, $data) {
            $expense = Expense::create($this->expenseAttributes($data) + [
                'receipt_path' => $request->hasFile('receipt') ? $request->file('receipt')->store('expenses', 'local') : null,
                'created_by' => Auth::id(),
            ]);
            app(OtherPayments::class)->applyExpensePayment($expense, $data);
            FinancialAuditLog::record('expense_created', 'expense', $expense->id, null, $expense->fresh()->snapshot());

            return $expense;
        });

        return response()->json(['message' => 'Gasto registrado.', 'expense' => $expense->fresh()->toPayload()], 201);
    }

    /** §83: edición con histórico. El motivo es obligatorio. */
    public function update(Request $request, Expense $expense): JsonResponse
    {
        if ($expense->voided_at) {
            return response()->json(['message' => 'Un gasto anulado no se edita.'], 422);
        }
        if ($expense->inventory_trip_id) {
            return response()->json(['message' => 'Es el gasto de un viaje de traslado: se corrige en el viaje (Inventario › Operaciones).'], 422);
        }
        if ($expense->cash_movement_id) {
            return response()->json(['message' => 'Es la comisión de un movimiento de caja: se anula el movimiento y se registra de nuevo (Finanzas › Caja).'], 422);
        }
        $data = $request->validate($this->expenseRules() + ['reason' => 'required|string|max:500'], self::MESSAGES);
        DB::transaction(function () use ($request, $data, $expense) {
            $before = $expense->snapshot();
            $expense->fill($this->expenseAttributes($data));
            if ($request->hasFile('receipt')) {
                // El comprobante anterior se queda guardado: no se borra nada (§83)
                $expense->receipt_path = $request->file('receipt')->store('expenses', 'local');
            }
            $expense->save();
            app(OtherPayments::class)->applyExpensePayment($expense->fresh(), $data);
            $after = $expense->fresh()->snapshot();
            if ($after !== $before) {
                FinancialAuditLog::record('expense_updated', 'expense', $expense->id, $before, $after, $data['reason']);
            }
        });

        return response()->json(['message' => 'Gasto corregido.', 'expense' => $expense->fresh()->toPayload()]);
    }

    /** §83: anulación. Sigue a la vista, pero deja de contar. */
    public function void(Request $request, Expense $expense): JsonResponse
    {
        if ($expense->voided_at) {
            return response()->json(['message' => 'Ese gasto ya estaba anulado.'], 422);
        }
        if ($expense->inventory_trip_id) {
            return response()->json(['message' => 'Es el gasto de un viaje de traslado: se anula el viaje (Inventario › Operaciones).'], 422);
        }
        if ($expense->cash_movement_id) {
            return response()->json(['message' => 'Es la comisión de un movimiento de caja: se anula el movimiento (Finanzas › Caja).'], 422);
        }
        $data = $request->validate(['reason' => 'required|string|max:500'], ['reason.required' => 'Escribe por qué se anula.']);
        app(OtherPayments::class)->beforeVoidExpense($expense);
        $expense->update(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $data['reason']]);
        FinancialAuditLog::record('expense_voided', 'expense', $expense->id, ['voided' => false], ['voided' => true], $data['reason']);

        return response()->json(['message' => 'Gasto anulado: ya no cuenta, pero queda en el historial.', 'expense' => $expense->fresh()->toPayload()]);
    }

    public function receipt(Expense $expense)
    {
        abort_unless($expense->receipt_path && Storage::disk('local')->exists($expense->receipt_path), 404, 'El comprobante ya no está.');

        return Storage::disk('local')->download($expense->receipt_path, "gasto-{$expense->id}." . pathinfo($expense->receipt_path, PATHINFO_EXTENSION));
    }

    public function recurringIndex(): JsonResponse
    {
        $items = RecurringExpense::with(['category:id,name', 'product:id,title', 'city:id,name', 'versions.creator:id,names'])
            ->orderByDesc('is_active')->orderBy('name')->get();
        $other = app(OtherPayments::class);
        $until = today()->addDays(30)->toDateString();
        $payments = RecurringExpensePayment::with('account:id,name')->whereIn('recurring_expense_id', $items->pluck('id'))
            ->orderByDesc('due_date')->orderByDesc('id')->get()->groupBy('recurring_expense_id');
        $payloads = $items->map(fn (RecurringExpense $r) => $r->toPayload() + [
            // §28: cada vencimiento se paga aparte y sale de la caja ese día (desde el arranque de la caja)
            'due' => $other->dueDates($r, $until),
            'payments' => ($payments->get($r->id) ?? collect())->take(10)->map->toPayload()->values(),
        ])->values();

        return response()->json([
            'recurring' => $payloads,
            'monthly_total' => round($payloads->where('active_today', true)->sum('monthly_usd'), 2),
        ]);
    }

    /** §28: el pago de un vencimiento de un gasto recurrente, desde una cuenta de la caja. */
    public function recurringPay(Request $request, RecurringExpense $recurring, OtherPayments $other): JsonResponse
    {
        $data = $request->validate([
            'due_date' => 'required|date',
            'paid_at' => 'required|date|before_or_equal:today',
            'financial_account_id' => 'nullable|integer|exists:financial_accounts,id',
            'amount' => 'required|numeric|min:0.01|max:9999999999',
            'currency' => ['required', Rule::in(Expense::CURRENCIES)],
            'rate' => 'nullable|required_if:currency,VES|numeric|min:0.0001',
            'notes' => 'nullable|string|max:500',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ], self::MESSAGES);
        $other->payRecurring($recurring, $data, $request->file('receipt'), Auth::id());

        return response()->json(['message' => 'Pago registrado: sale de la caja ese día. La rentabilidad no cambia (el gasto se reparte en lo que cubre).'], 201);
    }

    public function recurringPaymentVoid(Request $request, RecurringExpensePayment $payment, OtherPayments $other): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500'], ['reason.required' => 'Escribe por qué se anula.']);
        $other->voidRecurringPayment($payment, $data['reason'], Auth::id());

        return response()->json(['message' => 'Pago anulado: vuelve a la caja y el vencimiento queda sin pagar.']);
    }

    public function recurringStore(Request $request): JsonResponse
    {
        $data = $request->validate($this->recurringRules() + $this->amountRules(), self::MESSAGES);
        $recurring = DB::transaction(function () use ($data) {
            $recurring = RecurringExpense::create($this->recurringAttributes($data) + ['is_active' => true, 'created_by' => Auth::id()]);
            $version = $recurring->versions()->create($this->versionAttributes($data, true));
            FinancialAuditLog::record('recurring_expense_created', 'recurring_expense', $recurring->id, null,
                $recurring->fresh()->snapshot() + array_intersect_key($version->toPayload(), array_flip(['amount', 'currency', 'rate', 'amount_usd', 'valid_from'])));

            return $recurring;
        });

        return response()->json(['message' => "Gasto recurrente \"{$recurring->name}\" creado.", 'recurring' => $this->recurringPayload($recurring)], 201);
    }

    /** Nombre, categoría, frecuencia, día de pago, producto o ciudad. El monto no: va por versiones (§27). */
    public function recurringUpdate(Request $request, RecurringExpense $recurring): JsonResponse
    {
        $data = $request->validate($this->recurringRules() + ['reason' => 'required|string|max:500'], self::MESSAGES);
        DB::transaction(function () use ($recurring, $data) {
            $before = $recurring->snapshot();
            $recurring->update($this->recurringAttributes($data));
            $after = $recurring->fresh()->snapshot();
            if ($after !== $before) {
                FinancialAuditLog::record('recurring_expense_updated', 'recurring_expense', $recurring->id, $before, $after, $data['reason']);
            }
        });

        return response()->json(['message' => 'Gasto recurrente actualizado.', 'recurring' => $this->recurringPayload($recurring)]);
    }

    /**
     * §27: un monto nuevo desde una fecha, o activarlo o desactivarlo desde una fecha. Lo anterior no cambia. Con la misma
     * fecha que la última versión, la corrige (un error al cargarla), y también queda en el historial.
     */
    public function recurringVersion(Request $request, RecurringExpense $recurring): JsonResponse
    {
        $active = $request->boolean('is_active', true);
        $data = $request->validate(($active ? $this->amountRules() : ['valid_from' => 'required|date'])
            + ['reason' => 'required|string|max:500'], self::MESSAGES);
        $last = $recurring->versions()->get()->last();
        $from = Carbon::parse($data['valid_from'])->startOfDay();
        if ($last && $from->lt($last->valid_from)) {
            return response()->json(['message' => 'La fecha no puede ser anterior a la del último cambio (' . $last->valid_from->format('d/m/Y')
                . '). Lo anterior no se cambia: así los meses pasados conservan su monto.'], 422);
        }
        if (!$active && $last && !$last->is_active) {
            return response()->json(['message' => 'Ese gasto ya está desactivado.'], 422);
        }

        $attributes = $active ? $this->versionAttributes($data, true)
            : ['valid_from' => $from->toDateString(), 'is_active' => false, 'amount' => $last?->amount ?? 0, 'currency' => $last?->currency ?? 'USD',
                'rate' => $last?->rate ?? 1, 'amount_usd' => $last?->amount_usd ?? 0, 'created_by' => Auth::id()];
        $corrects = $last && $from->equalTo($last->valid_from);

        DB::transaction(function () use ($recurring, $attributes, $corrects, $last, $data) {
            $before = $last?->toPayload();
            if ($corrects) {
                $last->update($attributes);
                $version = $last->fresh();
            } else {
                $version = $recurring->versions()->create($attributes);
            }
            $recurring->update(['is_active' => (bool) $recurring->versions()->get()->last()->is_active]);
            FinancialAuditLog::record($corrects ? 'recurring_expense_version_corrected' : 'recurring_expense_version', 'recurring_expense',
                $recurring->id, $before, $version->toPayload(), $data['reason']);
        });

        $message = !$active ? 'Gasto desactivado desde el ' . $from->format('d/m/Y') . '.'
            : ($corrects ? 'Monto corregido.' : 'Monto nuevo desde el ' . $from->format('d/m/Y') . '. Lo anterior conserva su monto.');

        return response()->json(['message' => $message, 'recurring' => $this->recurringPayload($recurring)]);
    }

    /** El historial de un gasto o un recurrente (§82). */
    public function audit(Request $request): JsonResponse
    {
        $data = $request->validate(['subject_type' => ['required', Rule::in(['expense', 'recurring_expense', 'supplier', 'account_payable', 'inventory_purchase', 'inventory_trip', 'inventory_lot', 'financial_account', 'cash_movement'])],
            'subject_id' => 'required|integer']);

        return response()->json([
            'entries' => FinancialAuditLog::with('user:id,names')->where('subject_type', $data['subject_type'])
                ->where('subject_id', $data['subject_id'])->orderByDesc('id')->get()->map->toPayload(),
        ]);
    }

    private function expenseRules(): array
    {
        return [
            'concept' => 'required|string|max:150',
            'expense_category_id' => 'required|integer|exists:expense_categories,id',
            'amount' => 'required|numeric|min:0.01|max:9999999999',
            'currency' => ['required', Rule::in(Expense::CURRENCIES)],
            'rate' => 'nullable|required_if:currency,VES|numeric|min:0.0001',
            'expense_date' => 'required|date|before_or_equal:today',
            'description' => 'nullable|string|max:2000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'product_id' => 'nullable|integer|exists:products,id',
            'city_id' => 'nullable|integer|exists:cities,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'agency_id' => 'nullable|integer|exists:users,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            // Entrega 3d (§28, §44): cómo se pagó
            'payment' => ['nullable', Rule::in(['paid', 'payable', 'none'])],
            'financial_account_id' => 'nullable|integer|exists:financial_accounts,id',
            'payee_type' => ['nullable', Rule::in(array_keys(OtherPayments::PAYEE_TYPES))],
            'payee_name' => 'nullable|string|max:150',
            'due_date' => 'nullable|date',
        ];
    }

    private function expenseAttributes(array $data): array
    {
        $usd = Expense::toUsd((float) $data['amount'], $data['currency'], isset($data['rate']) ? (float) $data['rate'] : null);

        return [
            'concept' => trim($data['concept']),
            'expense_category_id' => $data['expense_category_id'],
            'amount' => round((float) $data['amount'], 2),
            'currency' => $data['currency'],
            'expense_date' => Carbon::parse($data['expense_date'])->toDateString(),
            'description' => $data['description'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'city_id' => $data['city_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'agency_id' => $data['agency_id'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
        ] + $usd;
    }

    private function recurringRules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'expense_category_id' => 'required|integer|exists:expense_categories,id',
            'frequency' => ['required', Rule::in(array_keys(RecurringExpense::FREQUENCIES))],
            'interval_days' => 'nullable|required_if:frequency,custom|integer|min:1|max:3650',
            'payment_day' => ['nullable', 'required_if:frequency,weekly,monthly', 'integer', 'min:1',
                fn ($attr, $value, $fail) => request('frequency') === 'weekly' && $value > 7 ? $fail('En un gasto semanal, el día de pago va del lunes al domingo.') : null,
                'max:31'],
            'payment_date' => 'nullable|required_if:frequency,annual,custom|date',
            'product_id' => 'nullable|integer|exists:products,id',
            'city_id' => 'nullable|integer|exists:cities,id',
        ];
    }

    private function recurringAttributes(array $data): array
    {
        $frequency = $data['frequency'];

        return [
            'name' => trim($data['name']),
            'expense_category_id' => $data['expense_category_id'],
            'frequency' => $frequency,
            'interval_days' => $frequency === 'custom' ? (int) $data['interval_days'] : null,
            'payment_day' => in_array($frequency, ['weekly', 'monthly'], true) ? (int) $data['payment_day'] : null,
            'payment_date' => in_array($frequency, ['annual', 'custom'], true) ? Carbon::parse($data['payment_date'])->toDateString() : null,
            'product_id' => $data['product_id'] ?? null,
            'city_id' => $data['city_id'] ?? null,
        ];
    }

    private function amountRules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01|max:9999999999',
            'currency' => ['required', Rule::in(Expense::CURRENCIES)],
            'rate' => 'nullable|required_if:currency,VES|numeric|min:0.0001',
            'valid_from' => 'required|date',
        ];
    }

    private function versionAttributes(array $data, bool $active): array
    {
        $usd = Expense::toUsd((float) $data['amount'], $data['currency'], isset($data['rate']) ? (float) $data['rate'] : null);

        return [
            'valid_from' => Carbon::parse($data['valid_from'])->toDateString(),
            'is_active' => $active,
            'amount' => round((float) $data['amount'], 2),
            'currency' => $data['currency'],
            'created_by' => Auth::id(),
        ] + $usd;
    }

    private function recurringPayload(RecurringExpense $recurring): array
    {
        return $recurring->fresh(['category:id,name', 'product:id,title', 'city:id,name', 'versions.creator:id,names'])->toPayload();
    }
}
