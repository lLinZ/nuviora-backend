<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Http\Controllers\Controller;
use App\Models\Status;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    public function get_last_currency()
    {
        $currency = Currency::whereHas('status', function ($query) {
            $query->where('description', 'Activo');
        })->get();
        return response()->json(['status' => true, 'data' => $currency[0]], 200);
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
     * Display the specified resource.
     */
    public function show(Currency $currency)
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
     * Update the specified resource in storage.
     */
    public function update(Request $request, Currency $currency)
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
