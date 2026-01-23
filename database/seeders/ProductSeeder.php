<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'title' => 'Tigrito Classic',
                'name' => 'Tigrito Classic',
                'price' => 25.00,
                'cost_usd' => 10.00,
                'sku' => 'TIG-CLA-001',
                'image' => 'https://via.placeholder.com/150?text=Tigrito+Classic',
            ],
            [
                'title' => 'Tigrito Ultra',
                'name' => 'Tigrito Ultra',
                'price' => 45.00,
                'cost_usd' => 18.00,
                'sku' => 'TIG-ULT-002',
                'image' => 'https://via.placeholder.com/150?text=Tigrito+Ultra',
            ],
            [
                'title' => 'Nuviora Cream Gold',
                'name' => 'Nuviora Cream Gold',
                'price' => 15.50,
                'cost_usd' => 6.20,
                'sku' => 'NUV-CRM-001',
                'image' => 'https://via.placeholder.com/150?text=Nuviora+Cream',
            ],
            [
                'title' => 'Nuviora Serum',
                'name' => 'Nuviora Serum',
                'price' => 30.00,
                'cost_usd' => 12.00,
                'sku' => 'NUV-SRM-002',
                'image' => 'https://via.placeholder.com/150?text=Nuviora+Serum',
            ],
        ];

        foreach ($products as $p) {
            Product::firstOrCreate(
                ['sku' => $p['sku']],
                array_merge($p, [
                    'product_id' => rand(1000000, 9999999),
                    'variant_id' => rand(1000000, 9999999),
                ])
            );
        }

        $this->command->info("✅ Productos de prueba creados.");
    }
}
