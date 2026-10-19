<?php

namespace App\Services\Replenishment;

use App\Models\City;
use App\Models\InventoryAuditLog;
use App\Models\Product;
use App\Models\ProductCityAvailability;
use App\Models\ProductInventorySetting;
use App\Models\ReplenishmentRoute;
use App\Models\Setting;
use App\Models\SupplierProduct;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La configuración del plan de inventario (Módulo 5 de Fran, entrega 2; §128: "centralizar configuraciones editables, no
 * hardcodear reglas comerciales"). Cada cambio queda en la auditoría (§95, §105: los cambios son explícitos y auditables).
 * - Globales, en settings: cobertura (§4, §5), seguridad (§6), % de Sin Stock (§24), pesos 7/14/30 (§28), mínimo de
 *   traslado (§68), pesos y rangos del score (§81, §82) y de la confiabilidad (§102). Los valores de partida son los del
 *   documento.
 * - Los que el documento no fija (R8: semáforo, stock muerto, Pausado y tendencia) son los que le propusimos a Fran
 *   (infra/modulo5/mensaje-para-fran-punto-8.txt) y él aprobó ("Perfecto"). Ya no queda ninguno pendiente; el mecanismo
 *   de "pendiente de Fran" (PROPOSED) queda por si algún valor nuevo hay que proponérselo.
 * - Por producto: estado comercial (§13), cobertura, seguridad y % de Sin Stock propios (§5, §6, §24), y disponibilidad
 *   por ciudad (§12). Proveedores por producto (§17–20, R5) y rutas (§60–65, R6).
 */
class ReplenishmentConfig
{
    public const KEY = 'replenishment.settings';
    public const CONFIRMED_KEY = 'replenishment.confirmed';

    public const DEFAULTS = [
        'coverage_days' => 7,                                                     // §4, §5
        'safety_days' => 1,                                                       // §6
        'sin_stock_pct' => 100,                                                   // §24
        'window_weights' => ['d7' => 50, 'd14' => 30, 'd30' => 20],               // §28
        'transfer_min_units' => 5,                                                // §68
        'score_weights' => ['stockout' => 35, 'velocity' => 25, 'lead_time' => 15, 'economics' => 15, 'confidence' => 10], // §81
        'score_ranges' => ['critical' => 90, 'high' => 70, 'monitor' => 40],     // §82
        'reliability_weights' => ['accuracy' => 60, 'stockout' => 25, 'excess' => 15], // §102
        'auto_plan_before_route' => 0,  // §66, §107: generar el plan solo la noche antes de un día de ruta; apagado
        // R8: propuestos a Fran (infra/modulo5/mensaje-para-fran-punto-8.txt) y aprobados por él ("Perfecto")
        'paused_demand_pct' => 50,    // §13: Pausado = forecast conservador
        'excess_multiplier' => 2,     // §73, §78: Exceso = más de N veces la cobertura objetivo
        'dead_stock_days' => 30,      // §74
        'trend_stable_pct' => 10,     // §32: estable si cambia menos de ±N %
    ];

    /** Los que el documento no fija: hasta que Fran los confirme o los cambie */
    public const PROPOSED = [];

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $stored = (array) Setting::get(self::KEY, []);
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = is_array($default) ? array_merge($default, (array) ($stored[$key] ?? [])) : ($stored[$key] ?? $default);
        }

        return $out;
    }

    /** Los propuestos que Fran todavía no confirmó */
    public function pending(): array
    {
        return array_values(array_diff(self::PROPOSED, (array) Setting::get(self::CONFIRMED_KEY, [])));
    }

    /**
     * Cambia los globales. Un valor propuesto que el Admin cambia, o todos si pide confirmarlos, deja de estar pendiente.
     */
    public function updateSettings(array $data, bool $confirmProposed = false, ?string $reason = null): array
    {
        $before = $this->settings();
        $after = $before;
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $data)) {
                $after[$key] = is_array($default) ? array_map(fn ($v) => (int) $v, array_intersect_key((array) $data[$key], $default) + $before[$key]) : $data[$key] + 0;
            }
        }
        $this->validate($after);

        $confirmed = (array) Setting::get(self::CONFIRMED_KEY, []);
        foreach (self::PROPOSED as $key) {
            if ($confirmProposed || $after[$key] != $before[$key]) {
                $confirmed[] = $key;
            }
        }
        DB::transaction(function () use ($after, $before, $confirmed, $reason) {
            Setting::set(self::KEY, $after);
            Setting::set(self::CONFIRMED_KEY, array_values(array_unique($confirmed)));
            InventoryAuditLog::record('replenishment_settings_updated', 'replenishment_settings', null, $before, $after, $reason);
        });

        return $after;
    }

    /** Todos los productos, con su configuración, sus ciudades y su stock (los que tienen stock primero). */
    public function products(): array
    {
        $settings = ProductInventorySetting::get()->keyBy('product_id');
        $availability = ProductCityAvailability::get()->groupBy('product_id');
        $cities = City::orderBy('name')->pluck('id');
        $stock = DB::table('inventories')->where('warehouse_id', '<>', Warehouse::transitId())->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) AS qty')->pluck('qty', 'product_id');
        $suppliers = SupplierProduct::where('is_active', true)->groupBy('product_id')->selectRaw('product_id, COUNT(*) AS n')->pluck('n', 'product_id');

        return Product::orderBy('title')->get(['id', 'title', 'showable_name'])->map(function (Product $p) use ($settings, $availability, $cities, $stock, $suppliers) {
            $s = $settings->get($p->id);
            $byCity = ($availability->get($p->id) ?? collect())->keyBy('city_id');

            return [
                'id' => $p->id,
                'name' => $p->showable_name ?: $p->title,
                'stock' => (int) ($stock[$p->id] ?? 0),
                'commercial_status' => $s?->commercial_status ?? ProductInventorySetting::ACTIVE,
                'coverage_days' => $s?->coverage_days,
                'safety_days' => $s?->safety_days,
                'sin_stock_pct' => $s?->sin_stock_pct,
                'notes' => $s?->notes,
                'cities' => $cities->mapWithKeys(fn ($id) => [$id => (bool) ($byCity->get($id)?->is_available ?? true)])->all(),
                'suppliers' => (int) ($suppliers[$p->id] ?? 0),
            ];
        })->sortByDesc(fn ($r) => $r['stock'] > 0)->values()->all();
    }

    public function updateProduct(Product $product, array $data): ProductInventorySetting
    {
        return DB::transaction(function () use ($product, $data) {
            $row = ProductInventorySetting::firstOrNew(['product_id' => $product->id]);
            $before = $row->exists ? $this->productSnapshot($row) : null;
            $row->fill([
                'commercial_status' => $data['commercial_status'],
                'coverage_days' => $data['coverage_days'] ?? null,
                'safety_days' => $data['safety_days'] ?? null,
                'sin_stock_pct' => $data['sin_stock_pct'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => Auth::id(),
            ])->save();
            $after = $this->productSnapshot($row);
            if ($after !== $before) {
                InventoryAuditLog::record('product_inventory_settings_updated', 'product', $product->id, $before, $after);
            }

            return $row;
        });
    }

    /** §12: producto disponible en una ciudad, Sí/No */
    public function setAvailability(Product $product, City $city, bool $available): void
    {
        DB::transaction(function () use ($product, $city, $available) {
            $row = ProductCityAvailability::firstOrNew(['product_id' => $product->id, 'city_id' => $city->id]);
            $before = $row->exists ? $row->is_available : true;
            $row->fill(['is_available' => $available, 'updated_by' => Auth::id()])->save();
            if ($before !== $available) {
                InventoryAuditLog::record('product_city_availability_updated', 'product', $product->id,
                    ['city' => $city->name, 'available' => $before], ['city' => $city->name, 'available' => $available]);
            }
        });
    }

    public function saveSupplierProduct(array $data, ?SupplierProduct $row = null): SupplierProduct
    {
        $exists = SupplierProduct::where('supplier_id', $data['supplier_id'])->where('product_id', $data['product_id'])
            ->when($row, fn ($q) => $q->where('id', '<>', $row->id))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['product_id' => 'Ese proveedor ya tiene ese producto: edítalo en vez de agregarlo otra vez.']);
        }
        if (!empty($data['receiving_warehouse_id']) && (int) $data['receiving_warehouse_id'] === Warehouse::transitId()) {
            throw ValidationException::withMessages(['receiving_warehouse_id' => '"En tránsito" no es un almacén de recepción.']);
        }

        return DB::transaction(function () use ($data, $row) {
            $row ??= new SupplierProduct();
            $before = $row->exists ? $row->fresh(['supplier', 'product', 'city', 'receivingWarehouse'])->toPayload() : null;
            $row->fill([
                'supplier_id' => $data['supplier_id'],
                'product_id' => $data['product_id'],
                'city_id' => $data['city_id'] ?? null,
                'expected_unit_cost_usd' => $data['expected_unit_cost_usd'] ?? null,
                'lead_time_days' => $data['lead_time_days'],
                'receiving_warehouse_id' => $data['receiving_warehouse_id'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'is_preferred' => (bool) ($data['is_preferred'] ?? false),
                'notes' => $data['notes'] ?? null,
            ])->save();
            // Un solo preferido por producto (§18: "proveedor preferido opcional")
            if ($row->is_preferred) {
                SupplierProduct::where('product_id', $row->product_id)->where('id', '<>', $row->id)->update(['is_preferred' => false]);
            }
            $after = $row->fresh(['supplier', 'product', 'city', 'receivingWarehouse'])->toPayload();
            InventoryAuditLog::record($before ? 'supplier_product_updated' : 'supplier_product_created', 'supplier_product', $row->id, $before, $after);

            return $row->fresh(['supplier', 'product', 'city', 'receivingWarehouse']);
        });
    }

    /** @param array{name:string, start_city_id?:?int, lead_time_days:float, usual_days?:array, cost_usd?:?float, is_active?:bool, notes?:?string, stops:int[]} $data */
    public function saveRoute(array $data, ?ReplenishmentRoute $route = null): ReplenishmentRoute
    {
        $stops = array_values(array_unique(array_map('intval', $data['stops'] ?? [])));
        if (count($stops) < 2) {
            throw ValidationException::withMessages(['stops' => 'Una ruta une al menos dos almacenes.']);
        }
        if (in_array(Warehouse::transitId(), $stops, true) || Warehouse::whereIn('id', $stops)->count() !== count($stops)) {
            throw ValidationException::withMessages(['stops' => 'Elige almacenes de verdad (no "En tránsito").']);
        }

        return DB::transaction(function () use ($data, $route, $stops) {
            $route ??= new ReplenishmentRoute();
            $before = $route->exists ? $route->load('stops.warehouse', 'startCity')->snapshot() : null;
            $route->fill([
                'name' => $data['name'],
                'start_city_id' => $data['start_city_id'] ?? null,
                'lead_time_days' => $data['lead_time_days'],
                'usual_days' => array_values(array_unique(array_map('intval', $data['usual_days'] ?? []))) ?: null,
                'cost_usd' => $data['cost_usd'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'notes' => $data['notes'] ?? null,
            ])->save();
            $route->stops()->delete();
            foreach ($stops as $i => $warehouseId) {
                $route->stops()->create(['warehouse_id' => $warehouseId, 'position' => $i + 1]);
            }
            $after = $route->fresh(['stops.warehouse', 'startCity'])->snapshot();
            InventoryAuditLog::record($before ? 'route_updated' : 'route_created', 'replenishment_route', $route->id, $before, $after);

            return $route->fresh(['stops.warehouse', 'startCity']);
        });
    }

    private function productSnapshot(ProductInventorySetting $row): array
    {
        return [
            'commercial_status' => $row->commercial_status,
            'coverage_days' => $row->coverage_days,
            'safety_days' => $row->safety_days,
            'sin_stock_pct' => $row->sin_stock_pct,
            'notes' => $row->notes,
        ];
    }

    /** Lo que no puede quedar mal configurado */
    private function validate(array $s): void
    {
        $errors = [];
        $ranges = [
            'coverage_days' => [1, 90, 'Cobertura'],
            'safety_days' => [0, 30, 'Días de seguridad'],
            'sin_stock_pct' => [0, 100, '% de Sin Stock'],
            'transfer_min_units' => [1, 1000, 'Mínimo de traslado'],
            'paused_demand_pct' => [0, 100, 'Demanda de un producto pausado'],
            'excess_multiplier' => [1.1, 10, 'Exceso'],
            'dead_stock_days' => [1, 365, 'Stock muerto'],
            'trend_stable_pct' => [1, 100, 'Tendencia estable'],
            'auto_plan_before_route' => [0, 1, 'Plan automático'],
        ];
        foreach ($ranges as $key => [$min, $max, $label]) {
            if ($s[$key] < $min || $s[$key] > $max) {
                $errors[$key] = "{$label}: entre {$min} y {$max}.";
            }
        }
        foreach (['window_weights' => 'Los pesos de 7, 14 y 30 días', 'score_weights' => 'Los pesos del score', 'reliability_weights' => 'Los pesos de la confiabilidad'] as $key => $label) {
            if (array_sum($s[$key]) !== 100 || min($s[$key]) < 0) {
                $errors[$key] = "{$label} tienen que sumar 100.";
            }
        }
        $r = $s['score_ranges'];
        if (!($r['critical'] <= 100 && $r['critical'] > $r['high'] && $r['high'] > $r['monitor'] && $r['monitor'] > 0)) {
            $errors['score_ranges'] = 'Los rangos del score van de mayor a menor: crítico > alta > monitorizar > 0.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
