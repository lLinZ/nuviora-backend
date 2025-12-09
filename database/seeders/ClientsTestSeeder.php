<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Client;
use Illuminate\Support\Str;

class ClientsTestSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            ['María', 'González'],
            ['Luis', 'Paredes'],
            ['Ana', 'Ramírez'],
            ['José', 'Fernández'],
            ['Carolina', 'Martínez'],
            ['Pedro', 'Chávez'],
            ['Valentina', 'Torres'],
            ['Sofía', 'Acosta'],
            ['Miguel', 'Morales'],
            ['Daniela', 'Salas'],
        ];

        foreach ($names as $person) {
            Client::firstOrCreate(
                ['email' => strtolower($person[0]) . '.' . strtolower($person[1]) . '@example.com'],
                [
                    'customer_id'     => rand(1000000, 9999999),
                    'customer_number' => Str::random(6),
                    'first_name'      => $person[0],
                    'last_name'       => $person[1],
                    'phone'           => '0414-' . rand(1000000, 9999999),
                    'country_name'    => 'Venezuela',
                    'country_code'    => 'VE',
                    'province'        => 'Zulia',
                    'city'            => 'Maracaibo',
                    'address1'        => 'Av. Siempre Viva #' . rand(1, 99),
                    'address2'        => null,
                ]
            );
        }

        $this->command->info("✅ Se generaron 10 clientes de prueba correctamente.");
    }
}
