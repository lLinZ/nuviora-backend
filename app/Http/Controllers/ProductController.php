<?php
// app/Http/Controllers/ProductController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Product::query();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('showable_name', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
        }

        if ($request->query('paginate') === 'false') {
            return response()->json($query->get());
        }

        $paginated = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'status' => true,
            'data'   => $paginated->items(),
            'meta'   => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $product = Product::with('gallery')->findOrFail($id);
        return response()->json([
            'status'  => true,
            'product' => $product
        ]);
    }

    public function uploadImage(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        $request->validate([
            'image' => 'required|image|max:5120', // 5MB max
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products/gallery', 'public');
            $image = $product->gallery()->create([
                'path' => Storage::url($path),
            ]);
            return response()->json(['status' => true, 'image' => $image, 'message' => 'Imagen subida']);
        }

        return response()->json(['status' => false, 'message' => 'No se subió ninguna imagen'], 400);
    }

    public function deleteImage($id, $imageId)
    {
        $product = Product::findOrFail($id);
        $image = $product->gallery()->findOrFail($imageId);
        
        // Delete from storage if it starts with /storage/
        if (str_starts_with($image->path, '/storage/')) {
            $storagePath = str_replace('/storage/', '', $image->path);
            Storage::disk('public')->delete($storagePath);
        }

        $image->delete();
        return response()->json(['status' => true, 'message' => 'Imagen eliminada']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sku' => 'nullable|string|unique:products,sku',
            'title' => 'nullable|string',
            'name' => 'nullable|string',
            'showable_name' => 'nullable|string',
            'price' => 'required|numeric',
            'cost_usd' => 'required|numeric',
            'image' => 'nullable|string',
            'stock' => 'nullable|integer',
            'description' => 'nullable|string',
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
            'showable_name' => 'nullable|string',
            'price' => 'sometimes|required|numeric',
            'cost_usd' => 'sometimes|required|numeric',
            'image' => 'nullable|string',
            'stock' => 'nullable|integer',
            'description' => 'nullable|string',
        ]);
        $p->fill($data);
        $p->save();
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

        $product->stock = $after;
        $product->save();

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
