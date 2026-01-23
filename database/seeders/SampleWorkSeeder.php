<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\Client;
use App\Models\Product;
use App\Models\OrderProduct;
use App\Models\Status;
use App\Models\Shop;
use Illuminate\Support\Str;

class SampleWorkSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Asegurar que haya un Shop
        $statusActivo = Status::firstOrCreate(['description' => 'Activo']);
        $shop = Shop::firstOrCreate(
            ['shopify_domain' => 'nuviora-test.myshopify.com'],
            [
                'name' => 'Nuviora Test Shop',
                'shopify_access_token' => 'shpat_test_token',
                'shopify_webhook_secret' => 'whsec_test_secret',
                'status_id' => $statusActivo->id,
            ]
        );

        // 2. Crear Clientes
        $clientes = [];
        for ($i = 0; $i < 5; $i++) {
            $clientes[] = Client::create([
                'customer_id' => (int) (now()->format('His') . random_int(1000, 9999)),
                'first_name' => 'Cliente ' . ($i + 1),
                'last_name' => 'Prueba',
                'phone' => '+58424' . random_int(1000000, 9999999),
                'email' => 'cliente' . ($i + 1) . '@example.com',
                'city' => 'Caracas',
                'address1' => 'Direccion de prueba ' . ($i + 1),
            ]);
        }

        // 3. Crear Productos
        $productos = [];
        $productData = [
            ['title' => 'Tigrito Classic', 'price' => 25.00, 'sku' => 'TIG-001'],
            ['title' => 'Tigrito Pro', 'price' => 45.00, 'sku' => 'TIG-002'],
            ['title' => 'Nuviora Cream', 'price' => 15.50, 'sku' => 'NUV-001'],
        ];

        foreach ($productData as $data) {
            $productos[] = Product::create([
                'product_id' => (int) (now()->format('His') . random_int(1000, 9999)),
                'variant_id' => (int) (now()->format('His') . random_int(1000, 9999)),
                'title' => $data['title'],
                'name' => $data['title'],
                'price' => $data['price'],
                'cost_usd' => $data['price'] * 0.4,
                'sku' => $data['sku'],
            ]);
        }

        // 4. Crear 10 Ordenes
        $statusNuevo = Status::where('description', '=', 'Nuevo')->first();
        
        for ($i = 0; $i < 10; $i++) {
            $cliente = $clientes[array_rand($clientes)];
            $orderId = (int) (now()->format('mdHis') . random_int(10, 99));

            $order = Order::create([
                'order_id' => $orderId,
                'order_number' => '100' . $i,
                'name' => '#100' . $i,
                'current_total_price' => 0, // Se calculará sumando productos
                'currency' => 'USD',
                'processed_at' => now(),
                'client_id' => $cliente->id,
                'status_id' => $statusNuevo->id,
                'shop_id' => $shop->id,
            ]);

            // Agregar 1-3 productos por orden
            $numItems = random_int(1, 3);
            $totalPrice = 0;

            $selectedProducts = (array) array_rand($productos, $numItems);
            if (!is_array($selectedProducts)) $selectedProducts = [$selectedProducts];

            foreach ($selectedProducts as $prodIdx) {
                $product = $productos[$prodIdx];
                $qty = random_int(1, 2);
                $subtotal = $product->price * $qty;
                $totalPrice += $subtotal;

                OrderProduct::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'title' => $product->title,
                    'name' => $product->title,
                    'price' => $product->price,
                    'quantity' => $qty,
                ]);
            }

            $order->fill(['current_total_price' => $totalPrice])->save();
        }
    }
}
