<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Http\Controllers\Controller;
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
        $currency = Currency::orderBy('created_at', 'DESC')->limit(1)->get();
        return response()->json(['status' => true, 'data' => $currency], 200);
    }

    public function get_latest_currencies()
    {
        $currencies = Currency::all()->orderBy('created_at', 'DESC')->limit(5);
        return response()->json(['status' => true, 'data' => $currencies], 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        //
        try {
            $currency = Currency::create([
                'description' => $request->description,
                'value' => $request->value,
            ]);
            return response()->json(['data' => $currency]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
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
