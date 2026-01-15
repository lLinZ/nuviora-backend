<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\User;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShopController extends Controller
{
    protected function ensureAdmin()
    {
        $role = Auth::user()->role?->description;
        if ($role !== 'Admin') {
            abort(403, 'No autorizado');
        }
    }

    public function index()
    {
        return Shop::with(['status', 'sellers'])->get();
    }

    public function store(Request $request)
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'name' => 'required|string',
            'shopify_domain' => 'nullable|string',
            'shopify_access_token' => 'nullable|string',
            'shopify_webhook_secret' => 'nullable|string',
            'status_id' => 'nullable|exists:statuses,id',
        ]);

        $shop = Shop::create($data);
        return response()->json($shop);
    }

    public function update(Request $request, Shop $shop)
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'name' => 'string',
            'shopify_domain' => 'nullable|string',
            'shopify_access_token' => 'nullable|string',
            'shopify_webhook_secret' => 'nullable|string',
            'status_id' => 'nullable|exists:statuses,id',
        ]);

        $shop->update($data);
        return response()->json($shop);
    }

    public function assignSellers(Request $request, Shop $shop)
    {
        $this->ensureAdmin();
        $data = $request->validate([
            'seller_ids' => 'required|array',
            'seller_ids.*' => 'exists:users,id',
        ]);

        $shop->sellers()->sync($data['seller_ids']);
        return response()->json(['message' => 'Vendedores asignados correctamente']);
    }

    public function destroy(Shop $shop)
    {
        $this->ensureAdmin();
        $shop->delete();
        return response()->json(['message' => 'Tienda eliminada']);
    }
}
