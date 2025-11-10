<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InventoryController extends Controller
{
    public function index()
    {
        return response()->json(['status' => true, 'data' => Inventory::with('product')->get()]);
    }
    public function adjust(Request $r, Product $product)
    {
        $r->validate(['type' => 'required|in:IN,OUT', 'quantity' => 'required|integer|min:1']);
        $inv = Inventory::firstOrCreate(['product_id' => $product->id]);
        $inv->quantity += ($r->type === 'IN' ? $r->quantity : -$r->quantity);
        if ($inv->quantity < 0) $inv->quantity = 0;
        $inv->save();
        StockMovement::create(['product_id' => $product->id, 'type' => $r->type, 'quantity' => $r->quantity, 'created_by' => Auth::id()]);
        return response()->json(['status' => true, 'message' => 'Stock actualizado', 'inventory' => $inv]);
    }
}
