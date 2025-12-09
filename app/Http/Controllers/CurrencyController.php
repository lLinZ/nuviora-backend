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

        $bcv_usd = Currency::where(function ($q) {
            $q->whereHas('status', fn($s) => $s->where('description', 'Activo'))
                ->where('description', 'bcv_usd');
        })->first();
        $bcv_eur = Currency::where(function ($q) {
            $q->whereHas('status', fn($s) => $s->where('description', 'Activo'))
                ->where('description', 'bcv_eur');
        })->first();
        $binance_usd = Currency::where(function ($q) {
            $q->whereHas('status', fn($s) => $s->where('description', 'Activo'))
                ->where('description', 'binance_usd');
        })->first();

        $updatedAt  = $binance_usd->created_at;

        return response()->json([
            'status' => true,
            'data'   => [
                'bcv_usd'      => $bcv_usd !== null ?  $bcv_usd : null,
                'bcv_eur'      => $bcv_eur !== null ?  $bcv_eur : null,
                'binance_usd'  => $binance_usd !== null ?  $binance_usd : null,
                'updated_at'   => $updatedAt, // string o null
                'has_values'   => $bcv_usd !== null || $bcv_eur !== null || $binance_usd !== null,
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
        $this->ensureManagerOrAdmin();

        $data = $request->validate([
            'bcv_usd'     => ['required', 'numeric', 'min:0'],
            'bcv_eur'     => ['required', 'numeric', 'min:0'],
            'binance_usd' => ['required', 'numeric', 'min:0'],
        ]);
        $bcv_usd = Currency::where(function ($q) {
            $q->whereHas('status', fn($s) => $s->where('description', 'Activo'))
                ->where('description', 'bcv_usd');
        })->first();
        $bcv_eur = Currency::where(function ($q) {
            $q->whereHas('status', fn($s) => $s->where('description', 'Activo'))
                ->where('description', 'bcv_eur');
        })->first();
        $binance_usd = Currency::where(function ($q) {
            $q->whereHas('status', fn($s) => $s->where('description', 'Activo'))
                ->where('description', 'binance_usd');
        })->first();

        $status_activo = Status::firstOrCreate([
            'description' => 'Activo',
        ]);
        $status_inactivo = Status::firstOrCreate([
            'description' => 'Inactivo',
        ]);
        if ($bcv_usd && $bcv_eur && $binance_usd) {
            $bcv_usd->status()->associate($status_inactivo);
            $bcv_usd->save();

            $bcv_eur->status()->associate($status_inactivo);
            $bcv_eur->save();

            $binance_usd->status()->associate($status_inactivo);
            $binance_usd->save();


            $currency_bcv_usd = Currency::create([
                'description' => 'bcv_usd',
                'value' => $request->bcv_usd,
            ]);
            $currency_bcv_usd->status()->associate($status_activo);
            $currency_bcv_usd->save();

            $currency_bcv_eur = Currency::create([
                'description' => 'bcv_eur',
                'value' => $request->bcv_eur,
            ]);
            $currency_bcv_eur->status()->associate($status_activo);
            $currency_bcv_eur->save();

            $currency_binance_usd = Currency::create([
                'description' => 'binance_usd',
                'value' => $request->binance_usd,
            ]);
            $currency_binance_usd->status()->associate($status_activo);
            $currency_binance_usd->save();
        } else {
            $currency_bcv_usd = Currency::create([
                'description' => 'bcv_usd',
                'value' => $request->bcv_usd,
            ]);
            $currency_bcv_usd->status()->associate($status_activo);
            $currency_bcv_usd->save();

            $currency_bcv_eur = Currency::create([
                'description' => 'bcv_eur',
                'value' => $request->bcv_eur,
            ]);
            $currency_bcv_eur->status()->associate($status_activo);
            $currency_bcv_eur->save();

            $currency_binance_usd = Currency::create([
                'description' => 'binance_usd',
                'value' => $request->binance_usd,
            ]);
            $currency_binance_usd->status()->associate($status_activo);
            $currency_binance_usd->save();
        }
        return response()->json([
            'data' => [
                'bcv_usd' => $currency_bcv_usd,
                'bcv_eur' => $currency_bcv_eur,
                'binance_usd' => $currency_binance_usd
            ]
        ], 200);
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
