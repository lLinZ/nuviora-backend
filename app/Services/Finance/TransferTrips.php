<?php

namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAuditLog;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferTrip;
use App\Models\Setting;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Viajes de traslado (Módulo 4 de Fran, entrega 2c; §13, §14, §15, R8).
 * - Un viaje agrupa traslados ya hechos (varios destinos y productos) y se paga una sola vez: mano de obra, combustible,
 *   otros y el desgaste del vehículo (§13). El desgaste sale de una regla configurable (§15): un % de lo demás, o un
 *   monto fijo en USD; por defecto, el 5 % (R8). El viaje guarda la regla que usó.
 * - Landed cost (§14): el total en USD se reparte entre todas las unidades movidas en el viaje. Ese costo por unidad se
 *   suma a la logística de las capas que llegaron con esos traslados (LotLedger), y entra a la ganancia cuando esas
 *   unidades se venden. No se cuenta dos veces: el gasto del viaje que se ve en Gastos (§13, para saber cuánto se gasta
 *   en transporte cada mes) no entra en la rentabilidad del período.
 * - Nada se borra (§83): un viaje se corrige con motivo o se anula, y todo queda en la auditoría (§82).
 */
class TransferTrips
{
    public const SETTING_WEAR_MODE = 'finance.vehicle_wear_mode';
    public const SETTING_WEAR_VALUE = 'finance.vehicle_wear_value';
    public const WEAR_MODES = ['percent' => 'Porcentaje de mano de obra + combustible + otros', 'fixed' => 'Monto fijo por viaje, en USD'];
    public const CATEGORY = 'Transporte / logística de inventario';
    /** Hasta cuántos días atrás se ofrecen los traslados para armar un viaje. */
    public const CANDIDATE_DAYS = 45;

    public function __construct(private LotLedger $ledger)
    {
    }

    /** @return array{mode: string, value: float} */
    public static function wearRule(): array
    {
        $mode = (string) Setting::get(self::SETTING_WEAR_MODE, 'percent');

        return ['mode' => array_key_exists($mode, self::WEAR_MODES) ? $mode : 'percent', 'value' => (float) Setting::get(self::SETTING_WEAR_VALUE, 0)];
    }

    /** Dólares con 2 decimales, o hasta 4 si hacen falta: "$0,55", "$0,525". */
    public static function usd(float $value): string
    {
        $text = number_format($value, 4, ',', '.');
        $text = preg_replace('/(,\d\d\d*?)0+$/', '$1', $text);

        return '$' . $text;
    }

    public static function wearLabel(string $mode, float $value): string
    {
        $n = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');

        return $mode === 'fixed' ? "\${$n} fijos por viaje" : "{$n} % de mano de obra + combustible + otros";
    }

    /**
     * §13, §15: el desgaste, el total y su equivalente en USD. Con la regla de %, el desgaste es ese % de lo demás; con
     * monto fijo, son esos USD en la moneda del viaje.
     *
     * @param array{mode: string, value: float} $rule
     * @return array{rate: float, wear: float, total: float, total_usd: float}
     */
    public static function totals(float $labor, float $fuel, float $other, string $currency, ?float $rate, array $rule): array
    {
        $rate = in_array($currency, ['USD', 'USDT'], true) ? 1.0 : (float) $rate;
        $base = $labor + $fuel + $other;
        $wear = round($rule['mode'] === 'fixed' ? $rule['value'] * $rate : $base * $rule['value'] / 100, 2);
        $total = round($base + $wear, 2);

        return ['rate' => $rate, 'wear' => $wear, 'total' => $total, 'total_usd' => $rate > 0 ? round($total / $rate, 2) : 0.0];
    }

    /**
     * Los traslados que pueden ir en un viaje: completados, de después del corte de los lotes, de los últimos días, y sin
     * viaje (o de este viaje, al corregirlo).
     */
    public function candidates(?InventoryTransferTrip $trip = null): Collection
    {
        $cutoff = (int) Setting::get(LotLedger::CUTOFF_KEY, 0);
        // Módulo 5 (§15): un traslado con tránsito tiene dos pasos. El viaje va en la salida (origen → En tránsito), así su
        // costo cuenta una sola vez y la capa recibida lo hereda. La recepción y la vuelta de un cancelado no son candidatas.
        $transit = Warehouse::transitId();

        $movements = InventoryMovement::with(['product:id,title,showable_name', 'variant:id,title', 'fromWarehouse:id,name', 'toWarehouse:id,name'])
            ->where('movement_type', 'transfer')->where('status', 'completed')->where('quantity', '>', 0)
            ->where('id', '>', $cutoff)
            ->where('from_warehouse_id', '<>', $transit)
            ->where('created_at', '>=', now()->subDays(self::CANDIDATE_DAYS))
            ->where(fn ($q) => $q->whereNull('trip_id')->when($trip, fn ($w) => $w->orWhere('trip_id', $trip->id)))
            ->orderByDesc('id')->get();

        // Para la salida de un traslado, el destino es el del traslado, no "En tránsito"
        $destinations = InventoryTransfer::with('toWarehouse:id,name')
            ->whereIn('id', $movements->where('to_warehouse_id', $transit)->where('reference_type', InventoryTransfer::REFERENCE)->pluck('reference_id')->unique())
            ->get()->keyBy('id');
        foreach ($movements as $m) {
            if ((int) $m->to_warehouse_id === $transit && ($t = $destinations[$m->reference_id] ?? null)) {
                $m->setRelation('toWarehouse', $t->toWarehouse);
            }
        }

        return $movements;
    }

    /**
     * @param array{trip_date: string, description?: ?string, currency: string, rate?: float|string|null, labor?: float|string|null,
     *              fuel?: float|string|null, other?: float|string|null, movement_ids: int[], financial_account_id?: ?int, notes?: ?string} $data
     */
    public function create(array $data, ?UploadedFile $receipt = null): InventoryTransferTrip
    {
        return DB::transaction(function () use ($data, $receipt) {
            $movements = $this->movementsFor($data['movement_ids'], null);
            $rule = self::wearRule();
            $trip = InventoryTransferTrip::create($this->attributes($data, $movements, $rule) + [
                'wear_mode' => $rule['mode'],
                'wear_value' => $rule['value'],
                'receipt_path' => $receipt?->store('trips', 'local'),
                'created_by' => Auth::id(),
            ]);
            InventoryMovement::whereIn('id', $movements->pluck('id'))->update(['trip_id' => $trip->id]);
            $this->syncExpense($trip);
            FinancialAuditLog::record('inventory_trip_created', 'inventory_trip', $trip->id, null, $trip->fresh(['account'])->snapshot());
            $this->ledger->recomputeLanded();

            return $trip->fresh();
        });
    }

    /** §83: corregir costos, fecha o traslados, con motivo. Conserva la regla de desgaste con la que se registró. */
    public function update(InventoryTransferTrip $trip, array $data, ?UploadedFile $receipt, string $reason): InventoryTransferTrip
    {
        if ($trip->voided_at) {
            throw ValidationException::withMessages(['trip' => 'Un viaje anulado no se corrige.']);
        }

        return DB::transaction(function () use ($trip, $data, $receipt, $reason) {
            $before = $trip->fresh(['account'])->snapshot();
            $movements = $this->movementsFor($data['movement_ids'], $trip);
            $trip->fill($this->attributes($data, $movements, ['mode' => $trip->wear_mode, 'value' => $trip->wear_value]));
            if ($receipt) {
                // El comprobante anterior se queda guardado: no se borra nada (§83)
                $trip->receipt_path = $receipt->store('trips', 'local');
            }
            $trip->save();
            InventoryMovement::where('trip_id', $trip->id)->whereNotIn('id', $movements->pluck('id'))->update(['trip_id' => null]);
            InventoryMovement::whereIn('id', $movements->pluck('id'))->update(['trip_id' => $trip->id]);
            $this->syncExpense($trip->fresh());
            $after = $trip->fresh(['account'])->snapshot();
            if ($after !== $before) {
                FinancialAuditLog::record('inventory_trip_updated', 'inventory_trip', $trip->id, $before, $after, $reason);
            }
            $this->ledger->recomputeLanded();

            return $trip->fresh();
        });
    }

    /** §83: el viaje no se borra. Se anula: sus traslados quedan libres y su costo sale de la logística y de los gastos. */
    public function void(InventoryTransferTrip $trip, string $reason): void
    {
        if ($trip->voided_at) {
            throw ValidationException::withMessages(['trip' => 'Ese viaje ya estaba anulado.']);
        }
        DB::transaction(function () use ($trip, $reason) {
            $before = $trip->fresh(['account'])->snapshot();
            InventoryMovement::where('trip_id', $trip->id)->update(['trip_id' => null]);
            $trip->update(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $reason]);
            Expense::where('inventory_trip_id', $trip->id)->whereNull('voided_at')
                ->update(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => "Viaje anulado: {$reason}"]);
            FinancialAuditLog::record('inventory_trip_voided', 'inventory_trip', $trip->id, $before, ['voided' => true], $reason);
            $this->ledger->recomputeLanded();
        });
    }

    public static function movementPayload(InventoryMovement $m): array
    {
        return [
            'id' => $m->id,
            'date' => $m->created_at?->format('Y-m-d H:i'),
            'product_id' => $m->product_id,
            'product' => $m->product?->showable_name ?: $m->product?->title,
            'variant' => $m->variant?->title ?? $m->size,
            'from' => $m->fromWarehouse?->name,
            'to' => $m->toWarehouse?->name,
            'quantity' => (int) $m->quantity,
            'trip_id' => $m->trip_id,
        ];
    }

    /** Los traslados elegidos: tienen que poder ir en el viaje (ver candidates). */
    private function movementsFor(array $ids, ?InventoryTransferTrip $trip): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $movements = $this->candidates($trip)->whereIn('id', $ids)->values();
        if ($movements->count() !== count($ids)) {
            throw ValidationException::withMessages(['movement_ids' => 'Algún traslado ya está en otro viaje, no es un traslado o es de antes del inventario por lotes. Vuelve a abrir la lista.']);
        }

        return $movements;
    }

    private function attributes(array $data, Collection $movements, array $rule): array
    {
        $labor = round((float) ($data['labor'] ?? 0), 2);
        $fuel = round((float) ($data['fuel'] ?? 0), 2);
        $other = round((float) ($data['other'] ?? 0), 2);
        $totals = self::totals($labor, $fuel, $other, $data['currency'], isset($data['rate']) ? (float) $data['rate'] : null, $rule);
        if ($totals['total_usd'] <= 0) {
            throw ValidationException::withMessages(['labor' => 'Escribe al menos un costo del viaje (mano de obra, combustible u otros).']);
        }
        $units = (int) $movements->sum('quantity');

        return [
            'trip_date' => Carbon::parse($data['trip_date'])->toDateString(),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'currency' => $data['currency'],
            'labor' => $labor,
            'fuel' => $fuel,
            'other' => $other,
            'units' => $units,
            'unit_cost_usd' => $units > 0 ? round($totals['total_usd'] / $units, 4) : 0,
            'financial_account_id' => $data['financial_account_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ] + $totals;
    }

    /**
     * §13: el gasto "Transporte / logística de inventario" del viaje, para saber cuánto se gasta en transporte cada mes.
     * Va marcado con el viaje: ExpenseLedger no lo cuenta en la rentabilidad del período, porque ya va en la logística.
     */
    private function syncExpense(InventoryTransferTrip $trip): void
    {
        $category = ExpenseCategory::firstOrCreate(['name' => self::CATEGORY]);
        $unit = self::usd($trip->unit_cost_usd);
        Expense::updateOrCreate(['inventory_trip_id' => $trip->id], [
            'concept' => 'Viaje de traslado' . ($trip->description ? ": {$trip->description}" : ''),
            'expense_category_id' => $category->id,
            'amount' => $trip->total,
            'currency' => $trip->currency,
            'rate' => $trip->rate,
            'amount_usd' => $trip->total_usd,
            'expense_date' => $trip->trip_date->toDateString(),
            'description' => "{$trip->units} unidades a {$unit} cada una. Va en la logística de lo que se vende, no como gasto del período.",
            'receipt_path' => $trip->receipt_path,
            'created_by' => $trip->created_by,
        ]);
    }
}
