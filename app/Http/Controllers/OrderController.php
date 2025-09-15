<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OrderProducts;
use App\Models\Product;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function handleOrderCreate(Request $request)
    {
        $orderData = $request->all();

        // 1. Guardar cliente
        $client = Client::updateOrCreate(
            ['customer_id' => $orderData['customer']['id']],
            [
                'customer_number' => $orderData['customer']['id'],
                'first_name' => $orderData['customer']['first_name'],
                'last_name' => $orderData['customer']['last_name'],
                'phone' => $orderData['customer']['phone'] ?? null,
                'email' => $orderData['customer']['email'],
                'country_name' => $orderData['customer']['default_address']['country'] ?? null,
                'country_code' => $orderData['customer']['default_address']['country_code'] ?? null,
                'province' => $orderData['customer']['default_address']['province'] ?? null,
                'city' => $orderData['customer']['default_address']['city'] ?? null,
                'address1' => $orderData['customer']['default_address']['address1'] ?? null,
                'address2' => $orderData['customer']['default_address']['address2'] ?? null,
            ]
        );

        // 2. Guardar orden
        $order = Order::updateOrCreate(
            ['order_id' => $orderData['id']],
            [
                'name' => $orderData['name'],
                'current_total_price' => $orderData['current_total_price'],
                'order_number' => $orderData['order_number'],
                'processed_at' => $orderData['processed_at'],
                'currency' => $orderData['currency'],
                'client_id' => $client->id,
            ]
        );

        // 3. Procesar productos de la orden
        foreach ($orderData['line_items'] as $item) {
            $product = Product::firstOrCreate(
                ['product_id' => $item['product_id']],
                [
                    'title' => $item['title'],
                    'name' => $item['name'],
                    'price' => $item['price'],
                    'sku' => $item['sku'] ?? null,
                    'image' => $item['image'] ?? null,
                ]
            );

            // Relación en OrderProducts
            OrderProducts::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_number' => $product->product_id,
                'title' => $item['title'],
                'name' => $item['name'],
                'price' => $item['price'],
                'quantity' => $item['quantity'],
                'image' => $item['image'] ?? null,
            ]);
        }

        return response()->json(['success' => true]);
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Order $order)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Order $order)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Order $order)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Order $order)
    {
        //
    }
}
