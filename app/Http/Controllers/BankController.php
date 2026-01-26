<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BankController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($request->has('all')) {
            return Bank::all();
        }
        return Bank::where('active', true)->orderBy('name')->get();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'code' => 'nullable|string',
            'active' => 'boolean'
        ]);

        $bank = Bank::create($validated);
        return response()->json($bank, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Bank $bank)
    {
        return $bank;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Bank $bank)
    {
        $validated = $request->validate([
            'name' => 'string',
            'code' => 'nullable|string',
            'active' => 'boolean'
        ]);

        $bank->update($validated);
        return $bank;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Bank $bank)
    {
        $bank->delete();
        return response()->json(null, 204);
    }
}
