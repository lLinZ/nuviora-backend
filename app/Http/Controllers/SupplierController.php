<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SupplierController extends Controller
{
    private function authorizeAdmin()
    {
        $user = Auth::user();
        if (!in_array($user?->role?->description, ['Admin', 'Gerente', 'Master'])) {
            abort(403, 'Solo administradores pueden gestionar proveedores.');
        }
    }

    /**
     * List all suppliers (Admin only)
     */
    public function index(Request $request)
    {
        $query = Supplier::query();

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        if ($request->has('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('contact_name', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%");
            });
        }

        $suppliers = $query->orderBy('name')->get();

        return response()->json(['success' => true, 'data' => $suppliers]);
    }

    /**
     * Create supplier
     */
    public function store(Request $request)
    {
        $this->authorizeAdmin();

        $validator = Validator::make($request->all(), [
            'name'                   => 'required|string|max:255',
            'contact_name'           => 'nullable|string|max:255',
            'phone'                  => 'nullable|string|max:50',
            'email'                  => 'nullable|email|max:255',
            'address'                => 'nullable|string',
            'currency'               => 'required|in:USD,VES',
            'default_lead_time_days' => 'required|integer|min:1|max:365',
            'notes'                  => 'nullable|string',
            'is_active'              => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $supplier = Supplier::create($request->only([
            'name', 'contact_name', 'phone', 'email', 'address',
            'currency', 'default_lead_time_days', 'notes', 'is_active',
        ]));

        return response()->json(['success' => true, 'data' => $supplier], 201);
    }

    /**
     * Show single supplier
     */
    public function show($id)
    {
        $supplier = Supplier::withCount('purchaseOrders')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $supplier]);
    }

    /**
     * Update supplier
     */
    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();

        $supplier = Supplier::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name'                   => 'sometimes|string|max:255',
            'contact_name'           => 'nullable|string|max:255',
            'phone'                  => 'nullable|string|max:50',
            'email'                  => 'nullable|email|max:255',
            'address'                => 'nullable|string',
            'currency'               => 'sometimes|in:USD,VES',
            'default_lead_time_days' => 'sometimes|integer|min:1|max:365',
            'notes'                  => 'nullable|string',
            'is_active'              => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $supplier->update($request->only([
            'name', 'contact_name', 'phone', 'email', 'address',
            'currency', 'default_lead_time_days', 'notes', 'is_active',
        ]));

        return response()->json(['success' => true, 'data' => $supplier]);
    }

    /**
     * Delete supplier (soft guard: must have no open OCs)
     */
    public function destroy($id)
    {
        $this->authorizeAdmin();

        $supplier = Supplier::findOrFail($id);

        $openOcs = $supplier->purchaseOrders()
            ->whereIn('status', ['draft', 'sent', 'confirmed', 'partial'])
            ->count();

        if ($openOcs > 0) {
            return response()->json([
                'success' => false,
                'message' => "No se puede eliminar: el proveedor tiene {$openOcs} orden(es) de compra abiertas.",
            ], 400);
        }

        $supplier->delete();

        return response()->json(['success' => true, 'message' => 'Proveedor eliminado.']);
    }
}
