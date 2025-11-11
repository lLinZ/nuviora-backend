<?php
// app/Http/Controllers/ProductController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $q = Product::query();

        if ($s = $request->get('search')) {
            $q->where(function ($w) use ($s) {
                $w->where('title', 'like', "%$s%")
                    ->orWhere('name', 'like', "%$s%")
                    ->orWhere('sku', 'like', "%$s%");
            });
        }

        $products = $q->orderBy('title')->get();

        return response()->json(['status' => true, 'data' => $products]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sku' => 'nullable|string|unique:products,sku',
            'title' => 'nullable|string',
            'name' => 'nullable|string',
            'price' => 'required|numeric',
            'cost' => 'required|numeric',
            'currency' => 'required|string|max:8',
            'stock' => 'required|integer',
            'image' => 'nullable|string',
        ]);

        $p = Product::create($data);
        return response()->json(['status' => true, 'product' => $p, 'message' => 'Producto creado']);
    }

    public function update(Request $request, $id)
    {
        $p = Product::findOrFail($id);
        $data = $request->validate([
            'sku' => 'nullable|string|unique:products,sku,' . $p->id,
            'title' => 'nullable|string',
            'name' => 'nullable|string',
            'price' => 'required|numeric',
            'cost' => 'required|numeric',
            'currency' => 'required|string|max:8',
            'stock' => 'required|integer',
            'image' => 'nullable|string',
        ]);
        $p->update($data);
        return response()->json(['status' => true, 'product' => $p, 'message' => 'Producto actualizado']);
    }
    // PUT /products/{product}/stock
    public function updateStock(Request $request, Product $product)
    {
        $data = $request->validate([
            'type'     => 'required|in:in,out,adjust',
            'quantity' => 'required|integer',
            'note'     => 'nullable|string|max:255',
        ]);

        $before = (int) $product->stock;
        $after  = $before;

        if ($data['type'] === 'in')  $after = $before + abs($data['quantity']);
        if ($data['type'] === 'out') $after = $before - abs($data['quantity']);
        if ($data['type'] === 'adjust') $after = (int) $data['quantity']; // set absoluto

        if ($after < 0) {
            return response()->json(['status' => false, 'message' => 'El stock no puede ser negativo'], 422);
        }

        $product->update(['stock' => $after]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'user_id'    => Auth::id(),
            'type'       => $data['type'],
            'quantity'   => ($data['type'] === 'adjust') ? ($after - $before) : ($data['type'] === 'out' ? -abs($data['quantity']) : abs($data['quantity'])),
            'before'     => $before,
            'after'      => $after,
            'note'       => $data['note'] ?? null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Stock actualizado',
            'product' => $product,
            'movement' => $movement,
        ]);
    }
}
