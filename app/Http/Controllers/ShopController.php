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
        $shops = Shop::with(['status', 'sellers'])->get();
        return response()->json([
            'status' => true,
            'data' => $shops
        ]);
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
            'auto_open_at' => 'nullable|date_format:H:i',
            'auto_close_at' => 'nullable|date_format:H:i',
            'auto_schedule_enabled' => 'boolean',
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
            'auto_open_at' => 'nullable|date_format:H:i',
            'auto_close_at' => 'nullable|date_format:H:i',
            'auto_schedule_enabled' => 'boolean',
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
            'default_roster_ids' => 'nullable|array',
            'default_roster_ids.*' => 'exists:users,id',
        ]);

        $sellerIds = $data['seller_ids'];
        $defaultRosterIds = $data['default_roster_ids'] ?? [];

        $syncData = [];
        foreach ($sellerIds as $id) {
            $syncData[$id] = ['is_default_roster' => in_array($id, $defaultRosterIds)];
        }

        $shop->sellers()->sync($syncData);
        return response()->json(['message' => 'Vendedores asignados correctamente']);
    }

    public function destroy(Shop $shop)
    {
        $this->ensureAdmin();
        $shop->delete();
        return response()->json(['message' => 'Tienda eliminada']);
    }
}
