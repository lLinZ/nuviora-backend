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
    protected function ensureManagerOrAdmin()
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Admin', 'Gerente'])) {
            abort(403, 'No autorizado');
        }
    }

    public function show()
    {
        $this->ensureManagerOrAdmin();

        $bcvUsd     = Setting::get('rate_bcv_usd');       // DÓLAR BCV
        $bcvEur     = Setting::get('rate_bcv_eur');       // EURO BCV
        $binanceUsd = Setting::get('rate_binance_usd');   // DÓLAR BINANCE
        $updatedAt  = Setting::get('rate_updated_at');    // opcional

        return response()->json([
            'status' => true,
            'data'   => [
                'bcv_usd'      => $bcvUsd !== null ? (float) $bcvUsd : null,
                'bcv_eur'      => $bcvEur !== null ? (float) $bcvEur : null,
                'binance_usd'  => $binanceUsd !== null ? (float) $binanceUsd : null,
                'updated_at'   => $updatedAt, // string o null
                'has_values'   => $bcvUsd !== null || $bcvEur !== null || $binanceUsd !== null,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $this->ensureManagerOrAdmin();

        $data = $request->validate([
            'bcv_usd'     => ['required', 'numeric', 'min:0'],
            'bcv_eur'     => ['required', 'numeric', 'min:0'],
            'binance_usd' => ['required', 'numeric', 'min:0'],
        ]);

        Setting::set('rate_bcv_usd', $data['bcv_usd']);
        Setting::set('rate_bcv_eur', $data['bcv_eur']);
        Setting::set('rate_binance_usd', $data['binance_usd']);
        Setting::set('rate_updated_at', now()->toDateTimeString());

        return response()->json([
            'status'  => true,
            'message' => 'Tasas actualizadas correctamente',
            'data'    => [
                'bcv_usd'     => (float) $data['bcv_usd'],
                'bcv_eur'     => (float) $data['bcv_eur'],
                'binance_usd' => (float) $data['binance_usd'],
                'updated_at'  => now()->toDateTimeString(),
            ],
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
