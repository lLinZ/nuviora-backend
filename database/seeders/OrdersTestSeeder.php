<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\Client;
use App\Models\Status;
use Illuminate\Support\Str;

class OrdersTestSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Asegurar que haya un Shop
        $statusActivo = Status::firstOrCreate(['description' => 'Activo']);
        $shop = \App\Models\Shop::firstOrCreate(
            ['shopify_domain' => 'nuviora-test.myshopify.com'],
            [
                'name' => 'Nuviora Test Shop',
                'shopify_access_token' => 'shpat_test_token',
                'shopify_webhook_secret' => 'whsec_test_secret',
                'status_id' => $statusActivo->id,
            ]
        );

        // Tomamos algunos clientes al azar
        $clientes = Client::inRandomOrder()->limit(10)->get();

        if ($clientes->count() == 0) {
            $this->command->error("⚠️ No existen clientes. Debes crear algunos primero.");
            return;
        }

        $productos = \App\Models\Product::all();
        if ($productos->count() == 0) {
            $this->command->error("⚠️ No existen productos. Ejecuta ProductSeeder primero.");
            return;
        }

        $statusNuevo = Status::where('description', 'Nuevo')->first();

        if (!$statusNuevo) {
            $this->command->error("⚠️ No existe el status 'Nuevo'. Crea ese registro en la tabla status.");
            return;
        }

        foreach (range(1, 15) as $i) {
            $cliente = $clientes->random();

            // genera un entero único grande (64-bit safe)
            $externalId = (int) (now()->format('YmdHis') . random_int(1000, 9999)); 

            $order = Order::create([
                'order_id'            => $externalId,
                'order_number'        => (string) random_int(100000, 999999),
                'name'                => 'ORD-' . strtoupper(Str::random(6)),
                'current_total_price' => 0, // Se actualizará
                'currency'            => 'USD',
                'processed_at'        => now()->subMinutes(random_int(10, 5000)),
                'client_id'           => $cliente->id,
                'status_id'           => $statusNuevo->id,
                'shop_id'             => $shop->id,
                'cancelled_at'        => null,
                'scheduled_for'       => null,
                'agent_id'            => null,
                'deliverer_id'        => null,
            ]);

            // Agregar 1-2 productos por orden
            $numItems = random_int(1, 2);
            $totalPrice = 0;
            $selectedProducts = $productos->random($numItems);

            foreach ($selectedProducts as $product) {
                $qty = random_int(1, 2);
                $subtotal = $product->price * $qty;
                $totalPrice += $subtotal;

                \App\Models\OrderProduct::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'title' => $product->title,
                    'name' => $product->title,
                    'price' => $product->price,
                    'quantity' => $qty,
                ]);
            }

            $order->update(['current_total_price' => $totalPrice]);
        }
    }
}
