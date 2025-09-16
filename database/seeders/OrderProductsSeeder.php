<?php

// database/seeders/OrderProductsSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\Product;
use App\Models\OrderProduct;

class OrderProductsSeeder extends Seeder
{
    public function run(): void
    {
        // Tomamos la primera orden y primer producto
        $order = Order::first();
        $product = Product::first();

        if ($order && $product) {
            OrderProduct::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_number' => $product->id,
                'title' => $product->title ?? 'Producto demo',
                'name' => $product->name ?? 'Producto demo',
                'price' => $product->price ?? 99.99,
                'quantity' => 2,
                'image' => $product->image ?? 'https://via.placeholder.com/150'
            ]);
        }
    }
}
