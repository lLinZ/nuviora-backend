<?php

namespace App\Http\Controllers;

use App\Models\Province;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProvinceController extends Controller
{
    protected function ensureAdmin()
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Admin', 'Gerente'])) {
            abort(403, 'No autorizado');
        }
    }

    public function index()
    {
        $provinces = Province::with(['agency'])->get();
        return response()->json([
            'status' => true,
            'data' => $provinces
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'name' => 'required|string|unique:provinces',
            'delivery_cost_usd' => 'nullable|numeric',
            'agency_id' => 'nullable|exists:users,id',
        ]);

        $province = Province::create($data);
        return response()->json($province);
    }

    public function update(Request $request, Province $province)
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'name' => 'string|unique:provinces,name,' . $province->id,
            'delivery_cost_usd' => 'nullable|numeric',
            'agency_id' => 'nullable|exists:users,id',
        ]);

        $province->update($data);
        return response()->json($province);
    }

    public function destroy(Province $province)
    {
        $this->ensureAdmin();
        $province->delete();
        return response()->json(['message' => 'Provincia eliminada']);
    }
}
