<?php

namespace App\Http\Controllers;

use App\Models\CompanyAccount;
use Illuminate\Http\Request;

class CompanyAccountController extends Controller
{
    public function index()
    {
        return response()->json(CompanyAccount::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string|max:255',
            'details' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $account = CompanyAccount::create($validated);

        return response()->json($account, 201);
    }

    public function show(CompanyAccount $companyAccount)
    {
        return response()->json($companyAccount);
    }

    public function update(Request $request, CompanyAccount $companyAccount)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'icon' => 'nullable|string|max:255',
            'details' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $companyAccount->update($validated);

        return response()->json($companyAccount);
    }

    public function destroy(CompanyAccount $companyAccount)
    {
        $companyAccount->delete();

        return response()->json(null, 204);
    }
}
