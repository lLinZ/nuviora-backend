<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CurrencyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }
    public function show()
    {
        // Solo admin / gerente ven y editan esto (puedes ajustar si quieres)
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Admin', 'Gerente'])) {
            return response()->json(['status' => false, 'message' => 'No autorizado'], 403);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'bcv_usd'     => (float) Setting::get('rate_bcv_usd', 1),
                'bcv_eur'     => (float) Setting::get('rate_bcv_eur', 1),
                'binance_usd' => (float) Setting::get('rate_binance_usd', 1),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Admin', 'Gerente'])) {
            return response()->json(['status' => false, 'message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'bcv_usd'     => ['required', 'numeric', 'min:0'],
            'bcv_eur'     => ['required', 'numeric', 'min:0'],
            'binance_usd' => ['required', 'numeric', 'min:0'],
        ]);

        Setting::set('rate_bcv_usd', $data['bcv_usd']);
        Setting::set('rate_bcv_eur', $data['bcv_eur']);
        Setting::set('rate_binance_usd', $data['binance_usd']);

        return response()->json([
            'status'  => true,
            'message' => 'Tasas actualizadas correctamente',
            'data'    => $data,
        ]);
    }
    public function get_last_currency()
    {
        $currency = Currency::whereHas('status', function ($query) {
            $query->where('description', 'Activo');
        })->get();
        return response()->json(['status' => true, 'data' => $currency], 200);
    }

    public function get_latest_currencies()
    {
        $currencies = Currency::all()->orderBy('created_at', 'DESC')->limit(5);
        return response()->json(['status' => true, 'data' => $currencies[0]], 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {

        $actual_currency = Currency::whereHas('status', function ($query) {
            $query->where('description', 'Activo');
        })->first();
        $status_inactivo = Status::where('description', 'Inactivo')->firstOrNew();
        if ($actual_currency) {
            $actual_currency->status()->associate($status_inactivo);
            $actual_currency->save();
        }
        //
        try {
            $currency = Currency::create([
                'description' => $request->description,
                'value' => $request->value,
            ]);
            $status_activo = Status::where('description', 'Activo')->firstOrNew();
            $currency->status()->associate($status_activo);
            $currency->save();
            return response()->json(['data' => $currency], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $request->all()
            ], 400);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }
    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Currency $currency)
    {
        //
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Currency $currency)
    {
        //
    }
}
