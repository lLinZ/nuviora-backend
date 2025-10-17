<?php

// app/Http/Controllers/ProductController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;

class ProductController extends Controller
{
    // GET /products?search=term&page=1
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
}
