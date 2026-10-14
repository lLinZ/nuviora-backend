<?php

namespace App\Http\Controllers;

use App\Jobs\CalculateDeliveryRoute;
use App\Models\AgencyTariff;
use App\Models\City;
use App\Models\DeliveryAuditLog;
use App\Models\OrderDeliveryRoute;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Delivery\DeliveryRoutes;
use App\Services\Delivery\MapsLinkResolver;
use App\Services\Delivery\TariffBook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Control interno de tarifas de delivery (Módulo 3 de Fran), solo administración (§12, §14, §39): los almacenes de las
 * agencias con sus coordenadas (§5, §6), los tarifarios con sus versiones (§10 a §13) y las rutas calculadas con su
 * costo esperado (§15, §42). La agencia no tiene acceso a nada de esto (la ruta exige el rol, §14).
 */
class DeliveryTariffController extends Controller
{
    /** Agencias, sus ciudades y sus almacenes. */
    public function warehouses()
    {
        $agencies = User::whereHas('role', fn ($q) => $q->where('description', 'Agencia'))->orderBy('names')->get(['id', 'names']);
        $cityNames = City::pluck('name', 'id');
        $agencyCities = \Illuminate\Support\Facades\DB::table('city_agency')->whereIn('agency_id', $agencies->pluck('id'))->get(['agency_id', 'city_id'])->groupBy('agency_id');
        $warehouses = Warehouse::whereIn('user_id', $agencies->pluck('id'))->orderBy('id')->get()->groupBy('user_id');

        return response()->json([
            'agencies' => $agencies->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->names,
                'cities' => collect($agencyCities->get($a->id, []))->pluck('city_id')->unique()->map(fn ($id) => ['id' => $id, 'name' => $cityNames[$id] ?? "#{$id}"])->values(),
                'warehouses' => collect($warehouses->get($a->id, []))->map(fn (Warehouse $w) => $this->presentWarehouse($w, $cityNames))->values(),
            ]),
            'cities' => City::orderBy('name')->get(['id', 'name']),
            // R11 (Módulo 4): los repartidores propios también pueden tener tarifario (salen del almacén que elige el Admin en Finanzas)
            'deliverers' => User::whereHas('role', fn ($q) => $q->where('description', 'Repartidor'))->orderBy('names')->get(['id', 'names'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->names, 'cities' => [], 'warehouses' => []])->values(),
        ]);
    }

    /** §6 y §39: ciudad, link de Maps y coordenadas (del link o a mano). §40: queda en la auditoría. */
    public function updateWarehouse(Request $request, Warehouse $warehouse, MapsLinkResolver $resolver, DeliveryRoutes $routes)
    {
        abort_unless($warehouse->user_id && User::whereKey($warehouse->user_id)->whereHas('role', fn ($q) => $q->where('description', 'Agencia'))->exists(), 404);
        $data = $request->validate([
            'city_id' => 'nullable|integer|exists:cities,id',
            'maps_url' => 'nullable|string|max:2000',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);
        $before = $warehouse->only(['city_id', 'maps_url', 'latitude', 'longitude']);

        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        $note = null;
        $urlChanged = array_key_exists('maps_url', $data) && trim((string) $data['maps_url']) !== trim((string) $warehouse->maps_url);
        if (($lat === null || $lng === null) && $urlChanged && trim((string) $data['maps_url']) !== '') {
            $r = $resolver->resolve($data['maps_url']);
            if ($r['status'] !== MapsLinkResolver::OK) {
                return response()->json(['status' => false, 'message' => 'No se pudieron leer las coordenadas del link: ' . $r['note'] . ' Pégalas a mano.'], 422);
            }
            [$lat, $lng, $note] = [$r['lat'], $r['lng'], $r['note']];
        }
        if (($lat === null) !== ($lng === null)) {
            return response()->json(['status' => false, 'message' => 'Faltan la latitud o la longitud.'], 422);
        }

        $warehouse->fill([
            'city_id' => array_key_exists('city_id', $data) ? $data['city_id'] : $warehouse->city_id,
            'maps_url' => array_key_exists('maps_url', $data) ? $data['maps_url'] : $warehouse->maps_url,
        ]);
        if ($lat !== null) {
            $warehouse->latitude = round((float) $lat, 7);
            $warehouse->longitude = round((float) $lng, 7);
        }
        $coordsChanged = $warehouse->isDirty(['latitude', 'longitude']);
        $warehouse->save();
        DeliveryAuditLog::record('warehouse_updated', 'warehouse', $warehouse->id, $before, $warehouse->only(['city_id', 'maps_url', 'latitude', 'longitude']));

        $recalculated = $coordsChanged ? $routes->refreshForWarehouse($warehouse, Auth::id()) : 0;

        return response()->json([
            'status' => true,
            'message' => 'Almacén guardado.' . ($recalculated ? ' Se calculan las rutas de ' . ($recalculated === 1 ? '1 orden.' : "{$recalculated} órdenes.") : ''),
            'note' => $note,
            'warehouse' => $this->presentWarehouse($warehouse->fresh(), City::pluck('name', 'id')),
        ]);
    }

    /** Vista previa: las coordenadas que salen de un link (sin guardar). */
    public function resolveLink(Request $request, MapsLinkResolver $resolver)
    {
        $data = $request->validate(['url' => 'required|string|max:2000']);

        return response()->json($resolver->resolve($data['url']));
    }

    /** §12 y §13: los tarifarios de cada agencia y ciudad, con todas sus versiones. */
    public function tariffs()
    {
        $today = now(config('app.timezone'))->toDateString();
        $tariffs = AgencyTariff::with(['ranges', 'agency:id,names', 'city:id,name', 'creator:id,names'])
            ->withCount('routes')->orderBy('agency_id')->orderBy('city_id')->orderByDesc('version')->get();

        return response()->json([
            'tariffs' => $tariffs->groupBy(fn ($t) => "{$t->agency_id}|{$t->city_id}")->map(function ($versions) use ($today) {
                $first = $versions->first();
                $current = $versions->first(fn ($t) => $t->is_active && $t->valid_from->toDateString() <= $today && ($t->valid_to === null || $t->valid_to->toDateString() >= $today));
                return [
                    'agency_id' => $first->agency_id,
                    'agency' => $first->agency?->names,
                    'city_id' => $first->city_id,
                    'city' => $first->city?->name,
                    'current_id' => $current?->id,
                    'versions' => $versions->map(fn (AgencyTariff $t) => [
                        'id' => $t->id,
                        'version' => $t->version,
                        'is_active' => $t->is_active,
                        'valid_from' => $t->valid_from->toDateString(),
                        'valid_to' => $t->valid_to?->toDateString(),
                        'notes' => $t->notes,
                        'created_by' => $t->creator?->names,
                        'created_at' => $t->created_at?->toDateTimeString(),
                        'routes' => $t->routes_count,
                        'ranges' => $t->ranges->map(fn ($r) => [
                            'min_km' => $r->min_km, 'max_km' => $r->max_km, 'price' => $r->price, 'price_per_km' => $r->price_per_km, 'label' => $r->label(),
                        ]),
                    ])->values(),
                ];
            })->values(),
        ]);
    }

    /** §11 a §13: una versión nueva (la anterior se cierra el día antes), o la corrección de la última, con su misma fecha. */
    public function storeTariff(Request $request, TariffBook $book)
    {
        $data = $request->validate([
            'agency_id' => 'required|integer|exists:users,id',
            'city_id' => 'required|integer|exists:cities,id',
            'valid_from' => 'required|date_format:Y-m-d',
            'notes' => 'nullable|string|max:300',
            'ranges' => 'required|array|min:1|max:50',
            'ranges.*.min_km' => 'required|numeric|min:0|max:10000',
            'ranges.*.max_km' => 'nullable|numeric|min:0|max:10000',
            'ranges.*.price' => 'nullable|numeric|min:0|max:10000',
            'ranges.*.price_per_km' => 'nullable|numeric|min:0|max:1000',
        ]);
        // R11 (Módulo 4): también el de un repartidor propio
        abort_unless(User::whereKey($data['agency_id'])->whereHas('role', fn ($q) => $q->whereIn('description', ['Agencia', 'Repartidor']))->exists(), 422,
            'Esa no es una agencia ni un repartidor.');
        if ($error = TariffBook::rangeErrors($data['ranges'])) {
            return response()->json(['status' => false, 'message' => $error], 422);
        }
        try {
            $tariff = $book->createVersion($data['agency_id'], $data['city_id'], $data['valid_from'], $data['ranges'], $data['notes'] ?? null, Auth::id());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        $message = "Tarifario versión {$tariff->version} guardado.";
        if ($book->corrected) {
            $n = $book->corrected['routes'];
            $message = "Versión {$tariff->version} guardada: corrige la versión {$book->corrected['version']}"
                . ($n ? ($n === 1 ? ' y se recalculó 1 carrera.' : " y se recalcularon {$n} carreras.") : '.');
        }

        return response()->json(['status' => true, 'message' => $message, 'tariff_id' => $tariff->id]);
    }

    /** §15 y §42: las rutas calculadas, con su estado. Por defecto, las activas. */
    public function routes(Request $request)
    {
        $data = $request->validate([
            'status' => 'nullable|string|max:24',
            'agency_id' => 'nullable|integer',
            'search' => 'nullable|string|max:60',
            'review' => 'nullable|boolean',
            'all' => 'nullable|boolean',
        ]);
        $query = OrderDeliveryRoute::with(['order:id,name,status_id', 'order.status:id,description', 'agency:id,names', 'warehouse:id,name', 'tariff:id,version', 'range'])
            ->withCount(['trips' => fn ($q) => $q->whereNull('voided_at')])
            ->when(!($data['all'] ?? false), fn ($q) => $q->where('is_active', true))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('calculation_status', $s))
            ->when($data['agency_id'] ?? null, fn ($q, $a) => $q->where('agency_id', $a))
            ->when($data['review'] ?? false, fn ($q) => $q->where('requires_review', true))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->whereHas('order', fn ($o) => $o->where('name', 'like', '%' . ltrim($s, '#') . '%')))
            ->orderByDesc('id');
        $page = $query->paginate(50);
        $counts = OrderDeliveryRoute::where('is_active', true)->selectRaw('calculation_status, COUNT(*) n')->groupBy('calculation_status')->pluck('n', 'calculation_status');

        return response()->json([
            'routes' => collect($page->items())->map(fn (OrderDeliveryRoute $r) => [
                'id' => $r->id,
                'order_id' => $r->order_id,
                'order' => $r->order?->name,
                'order_status' => $r->order?->status?->description,
                'agency' => $r->agency?->names,
                'warehouse' => $r->warehouse?->name,
                'distance_km' => $r->distance_km,
                'range' => $r->range?->label(),
                'tariff_version' => $r->tariff?->version,
                'tariff_date' => $r->tariff_date?->toDateString(),
                'expected_cost' => $r->expected_cost,
                'status' => $r->calculation_status,
                'status_label' => OrderDeliveryRoute::STATUS_LABEL[$r->calculation_status] ?? $r->calculation_status,
                'error' => $r->error,
                'attempts' => $r->attempts,
                'is_active' => $r->is_active,
                'reason' => $r->reason,
                'requires_review' => $r->requires_review,
                'review_note' => $r->review_note,
                'distance_approved_at' => $r->distance_approved_at?->toDateTimeString(),
                'trips' => $r->trips_count,
                'maps_url' => $r->maps_url,
                'calculated_at' => $r->calculated_at?->toDateTimeString(),
                'created_at' => $r->created_at?->toDateTimeString(),
            ]),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'counts' => $counts,
            'labels' => OrderDeliveryRoute::STATUS_LABEL,
            'google_configured' => app(\App\Services\Delivery\GoogleRoutesClient::class)->configured(),
        ]);
    }

    /**
     * Fran (2026-10-12): una carrera de más km que el límite de su agencia queda por revisar. Si la distancia es real (un
     * cliente lejos de verdad), el Admin la aprueba y su costo cuenta en el cierre; si no, se corrige la ubicación.
     */
    public function approveDistance(OrderDeliveryRoute $deliveryRoute)
    {
        $route = $deliveryRoute;
        if ($route->calculation_status !== OrderDeliveryRoute::DISTANCE_REVIEW) {
            return response()->json(['status' => false, 'message' => 'Esta carrera no está esperando revisión de distancia.'], 422);
        }
        $route->update(['calculation_status' => OrderDeliveryRoute::CALCULATED, 'distance_approved_by' => Auth::id(), 'distance_approved_at' => now()]);
        DeliveryAuditLog::record('route_distance_approved', 'order_delivery_route', $route->id,
            ['calculation_status' => OrderDeliveryRoute::DISTANCE_REVIEW], ['calculation_status' => OrderDeliveryRoute::CALCULATED, 'distance_km' => $route->distance_km, 'expected_cost' => $route->expected_cost], Auth::id());

        return response()->json(['status' => true, 'message' => 'Distancia aprobada: la carrera cuenta con $' . number_format((float) $route->expected_cost, 2) . '.']);
    }

    /** §42: reintento manual. Una ruta con error se vuelve a pedir; las demás se ponen al día con los datos de hoy. */
    public function retryRoute(OrderDeliveryRoute $deliveryRoute, DeliveryRoutes $routes)
    {
        $route = $deliveryRoute;
        if ($route->calculation_status === OrderDeliveryRoute::ERROR) {
            $route->update(['calculation_status' => OrderDeliveryRoute::PENDING, 'error' => null]);
            CalculateDeliveryRoute::dispatch($route->id);
            return response()->json(['status' => true, 'message' => 'Calculando otra vez…']);
        }
        if (!$route->is_active) {
            return response()->json(['status' => false, 'message' => 'Esta ruta ya fue reemplazada por otra.'], 422);
        }
        $new = $routes->refresh($route->order, 'recalculo', Auth::id());
        if (!$new) {
            return response()->json(['status' => false, 'message' => 'La orden ya no está con una agencia.'], 422);
        }
        return response()->json(['status' => true, 'message' => $new->id === $route->id && $route->calculation_status === OrderDeliveryRoute::CALCULATED
            ? 'Ya está calculada con los datos actuales (no se vuelve a consultar a Google).'
            : 'Ruta puesta al día: ' . (OrderDeliveryRoute::STATUS_LABEL[$new->calculation_status] ?? $new->calculation_status) . '.']);
    }

    private function presentWarehouse(Warehouse $w, $cityNames): array
    {
        return [
            'id' => $w->id,
            'name' => $w->name,
            'city_id' => $w->city_id,
            'city' => $w->city_id ? ($cityNames[$w->city_id] ?? null) : null,
            'maps_url' => $w->maps_url,
            'latitude' => $w->latitude,
            'longitude' => $w->longitude,
            'is_active' => (bool) $w->is_active,
            'created_at' => $w->created_at?->toDateString(),
        ];
    }
}
