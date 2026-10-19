<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\InventoryAuditLog;
use App\Models\Product;
use App\Models\ProductInventorySetting;
use App\Models\ReplenishmentRoute;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Warehouse;
use App\Services\Replenishment\ReplenishmentConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reabastecimiento › Configuración (Módulo 5 de Fran, entrega 2). Solo Admin/propietario (§3).
 */
class ReplenishmentConfigController extends Controller
{
    public function __construct(private ReplenishmentConfig $config)
    {
    }

    /** Todo lo que necesita la pantalla de configuración. */
    public function index(): JsonResponse
    {
        $transit = Warehouse::transitId();

        return response()->json([
            'settings' => $this->config->settings(),
            'defaults' => ReplenishmentConfig::DEFAULTS,
            'pending' => $this->config->pending(),
            'statuses' => ProductInventorySetting::STATUSES,
            'days' => ReplenishmentRoute::DAYS,
            'products' => $this->config->products(),
            'cities' => City::orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::where('id', '<>', $transit)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'city_id']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name', 'is_active']),
            'supplier_products' => SupplierProduct::with(['supplier:id,name', 'product:id,title,showable_name', 'city:id,name', 'receivingWarehouse:id,name'])
                ->orderBy('product_id')->get()->map->toPayload()->values(),
            'routes' => ReplenishmentRoute::with(['stops.warehouse:id,name,city_id', 'startCity:id,name'])->orderBy('name')->get()->map->toPayload()->values(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $int = 'integer|min:0|max:100';
        $data = $request->validate([
            'coverage_days' => 'sometimes|numeric',
            'safety_days' => 'sometimes|numeric',
            'sin_stock_pct' => 'sometimes|integer',
            'transfer_min_units' => 'sometimes|integer',
            'window_weights' => 'sometimes|array',
            'window_weights.*' => $int,
            'score_weights' => 'sometimes|array',
            'score_weights.*' => $int,
            'score_ranges' => 'sometimes|array',
            'score_ranges.*' => $int,
            'reliability_weights' => 'sometimes|array',
            'reliability_weights.*' => $int,
            'paused_demand_pct' => 'sometimes|numeric',
            'excess_multiplier' => 'sometimes|numeric',
            'dead_stock_days' => 'sometimes|integer',
            'trend_stable_pct' => 'sometimes|numeric',
            'auto_plan_before_route' => 'sometimes|boolean',
            'confirm_proposed' => 'sometimes|boolean',
            'reason' => 'nullable|string|max:500',
        ]);
        $settings = $this->config->updateSettings($data, (bool) ($data['confirm_proposed'] ?? false), $data['reason'] ?? null);

        return response()->json(['message' => 'Configuración guardada.', 'settings' => $settings, 'pending' => $this->config->pending()]);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'commercial_status' => ['required', Rule::in(array_keys(ProductInventorySetting::STATUSES))],
            'coverage_days' => 'nullable|numeric|min:1|max:90',
            'safety_days' => 'nullable|numeric|min:0|max:30',
            'sin_stock_pct' => 'nullable|integer|min:0|max:100',
            'notes' => 'nullable|string|max:500',
        ]);
        $this->config->updateProduct($product, $data);

        return response()->json(['message' => 'Producto guardado.']);
    }

    public function setAvailability(Request $request, Product $product, City $city): JsonResponse
    {
        $data = $request->validate(['is_available' => 'required|boolean']);
        $this->config->setAvailability($product, $city, (bool) $data['is_available']);

        return response()->json(['message' => $data['is_available'] ? "Disponible en {$city->name}." : "No disponible en {$city->name}: el plan no compra ni manda ahí."]);
    }

    public function storeSupplierProduct(Request $request): JsonResponse
    {
        $row = $this->config->saveSupplierProduct($this->supplierProductData($request));

        return response()->json(['message' => 'Proveedor agregado al producto.', 'supplier_product' => $row->toPayload()], 201);
    }

    public function updateSupplierProduct(Request $request, SupplierProduct $supplierProduct): JsonResponse
    {
        $row = $this->config->saveSupplierProduct($this->supplierProductData($request), $supplierProduct);

        return response()->json(['message' => 'Guardado.', 'supplier_product' => $row->toPayload()]);
    }

    public function storeRoute(Request $request): JsonResponse
    {
        $route = $this->config->saveRoute($this->routeData($request));

        return response()->json(['message' => 'Ruta creada.', 'route' => $route->toPayload()], 201);
    }

    public function updateRoute(Request $request, ReplenishmentRoute $route): JsonResponse
    {
        $route = $this->config->saveRoute($this->routeData($request), $route);

        return response()->json(['message' => 'Ruta guardada.', 'route' => $route->toPayload()]);
    }

    /** §95: la auditoría del inventario y de su configuración, lo más nuevo primero. */
    public function audit(Request $request): JsonResponse
    {
        $data = $request->validate(['entity_type' => 'nullable|string|max:40', 'limit' => 'nullable|integer|min:1|max:500']);
        $rows = InventoryAuditLog::with('user:id,names')
            ->when($data['entity_type'] ?? null, fn ($q, $t) => $q->where('entity_type', $t))
            ->orderByDesc('id')->limit($data['limit'] ?? 200)->get()
            ->map(fn (InventoryAuditLog $l) => [
                'id' => $l->id,
                'at' => $l->created_at?->format('Y-m-d H:i'),
                'user' => $l->user?->names,
                'action' => $l->action,
                'entity_type' => $l->entity_type,
                'entity_id' => $l->entity_id,
                'before' => $l->before,
                'after' => $l->after,
                'reason' => $l->reason,
            ]);

        return response()->json(['rows' => $rows]);
    }

    private function supplierProductData(Request $request): array
    {
        return $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'product_id' => 'required|integer|exists:products,id',
            'city_id' => 'nullable|integer|exists:cities,id',
            'expected_unit_cost_usd' => 'nullable|numeric|min:0|max:99999',
            'lead_time_days' => 'required|numeric|min:0|max:90',
            'receiving_warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'is_active' => 'sometimes|boolean',
            'is_preferred' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:500',
        ], ['lead_time_days.required' => 'Escribe cuántos días tarda en despachar (0 si es el mismo día).']);
    }

    private function routeData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'start_city_id' => 'nullable|integer|exists:cities,id',
            'lead_time_days' => 'required|numeric|min:0|max:30',
            'usual_days' => 'nullable|array',
            'usual_days.*' => 'integer|min:1|max:7',
            'cost_usd' => 'nullable|numeric|min:0|max:99999',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:500',
            'stops' => 'required|array|min:2',
            'stops.*' => 'integer',
        ], ['stops.min' => 'Una ruta une al menos dos almacenes.']);
    }
}
