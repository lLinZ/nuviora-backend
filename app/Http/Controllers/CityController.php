<?php

namespace App\Http\Controllers;

use App\Models\City;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function index()
    {
        return response()->json(City::with('agency')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|unique:cities',
            'delivery_cost_usd' => 'required|numeric|min:0',
            'agency_id' => 'nullable|exists:users,id',
        ]);

        $city = City::create($data);
        return response()->json($city->load('agency'));
    }

    public function show(City $city)
    {
        return response()->json($city->load('agency'));
    }

    public function update(Request $request, City $city)
    {
        $data = $request->validate([
            'name' => 'required|string|unique:cities,name,' . $city->id,
            'delivery_cost_usd' => 'required|numeric|min:0',
            'agency_id' => 'nullable|exists:users,id',
        ]);

        $city->update($data);
        return response()->json($city->load('agency'));
    }

    public function destroy(City $city)
    {
        $city->delete();
        return response()->json(['message' => 'Ciudad eliminada']);
    }
}
