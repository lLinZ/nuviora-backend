<?php

// app/Http/Controllers/StockMovementController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;

class StockMovementController extends Controller
{
    // GET /products/{product}/movements
    public function index(Request $request, Product $product)
    {
        $list = $product->stockMovements()->with('user:id,names,surnames,email')
            ->latest('id')
            ->paginate(30);

        return response()->json([
            'status' => true,
            'data'   => $list->items(),
            'meta'   => [
                'current_page' => $list->currentPage(),
                'total'        => $list->total(),
                'last_page'    => $list->lastPage(),
            ],
        ]);
    }
}
