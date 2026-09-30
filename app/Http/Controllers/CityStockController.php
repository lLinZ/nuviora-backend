<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Services\Inventory\CityStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Inventario por ciudad (Fran, 2026-09-30): la vendedora ve qué hay en cada ciudad, con sus tallas,
 * sin el nombre de las agencias. El alta manual lo usa para decir qué tallas hay en la ciudad del cliente.
 */
class CityStockController extends Controller
{
    public function __construct(private CityStock $stock)
    {
    }

    /** GET /inventory/by-city?city=Carabobo&product_ids=1,2 */
    public function index(Request $request): JsonResponse
    {
        if (Auth::user()->role?->description === 'Agencia') {
            return response()->json(['status' => false, 'message' => 'Las agencias ven su propio inventario.'], 403);
        }
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'product_ids' => 'nullable|string|max:1000',
        ]);

        $cityId = null;
        if (!empty($data['city'])) {
            $city = ctype_digit($data['city'])
                ? City::find((int) $data['city'])
                : City::whereRaw('UPPER(name) = ?', [mb_strtoupper(trim($data['city']))])->first();
            if (!$city) {
                return response()->json(['status' => true, 'data' => []]);
            }
            $cityId = $city->id;
        }
        $productIds = isset($data['product_ids'])
            ? array_values(array_unique(array_filter(array_map('intval', explode(',', $data['product_ids'])))))
            : null;

        $byCity = $this->stock->warehousesByCity($cityId);
        $names = City::whereIn('id', array_keys($byCity))->pluck('name', 'id');
        $out = [];
        foreach ($byCity as $id => $warehouseIds) {
            if ($warehouseIds === [] && !$cityId) {
                continue; // ciudades sin agencia: no hay nada que mostrar
            }
            $out[] = [
                'city_id' => $id,
                'city' => $names[$id] ?? '',
                'has_agency' => $warehouseIds !== [],
                'products' => $this->stock->forWarehouses($warehouseIds, $productIds),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['city'], $b['city']));

        return response()->json(['status' => true, 'data' => $out]);
    }
}
