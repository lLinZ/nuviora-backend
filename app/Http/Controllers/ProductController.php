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
        $q = trim((string) $request->get('search', ''));
        $perPage = (int) $request->get('per_page', 20);

        $query = Product::query()
            ->select('id', 'name', 'title', 'sku', 'price', 'image', 'stock', 'created_at');

        if ($q !== '') {
            $query->where(function ($qq) use ($q) {
                $qq->where('name', 'like', "%$q%")
                    ->orWhere('title', 'like', "%$q%")
                    ->orWhere('sku', 'like', "%$q%");
            });
        }

        $products = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'status' => true,
            'data'   => $products->items(),
            'meta'   => [
                'current_page' => $products->currentPage(),
                'total'        => $products->total(),
                'last_page'    => $products->lastPage(),
            ],
        ]);
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
