<?php

// app/Http/Controllers/StockMovementController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    // GET /products/{product}/movements
    public function index(Request $request)
    {
        $q = StockMovement::with(['product:id,sku,title,name', 'user:id,names,surnames,email'])->orderByDesc('id');
        if ($sku = $request->get('sku')) {
            $q->whereHas('product', fn($w) => $w->where('sku', $sku));
        }
        if ($from = $request->get('from')) $q->whereDate('created_at', '>=', $from);
        if ($to = $request->get('to')) $q->whereDate('created_at', '<=', $to);

        return response()->json(['status' => true, 'data' => $q->paginate(50)]);
    }

    public function adjust(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:IN,OUT',
            'quantity' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($data) {
            $p = Product::lockForUpdate()->find($data['product_id']);

            $newStock = $data['type'] === 'IN'
                ? $p->stock + $data['quantity']
                : $p->stock - $data['quantity'];

            if ($newStock < 0) {
                return response()->json(['status' => false, 'message' => 'Stock insuficiente'], 422);
            }

            $p->update(['stock' => $newStock]);

            $m = StockMovement::create([
                'product_id' => $p->id,
                'user_id' => Auth::id(),
                'type' => $data['type'],
                'quantity' => $data['quantity'],
                'reason' => $data['reason'] ?? null,
                'meta' => null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Stock actualizado',
                'product' => $p,
                'movement' => $m
            ]);
        });
    }
}
