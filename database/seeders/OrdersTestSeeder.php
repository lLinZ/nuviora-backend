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
        // Tomamos algunos clientes al azar
        $clientes = Client::inRandomOrder()->limit(10)->get();

        if ($clientes->count() == 0) {
            $this->command->error("⚠️ No existen clientes. Debes crear algunos primero.");
            return;
        }

        $statusNuevo = Status::where('description', 'Nuevo')->first();

        if (!$statusNuevo) {
            $this->command->error("⚠️ No existe el status 'Nuevo'. Crea ese registro en la tabla status.");
            return;
        }

        foreach (range(1, 40) as $i) {
            $cliente = $clientes->random();

            // genera un entero único grande (64-bit safe)
            $externalId = (int) (now()->format('YmdHis') . random_int(1000, 9999)); // p.ej. 202511060153449876

            Order::create([
                'order_id'            => $externalId,                 // 👈 entero, no UUID
                'order_number'        => random_int(100000, 999999),
                'name'                => 'ORD-' . strtoupper(Str::random(6)),
                'current_total_price' => random_int(10, 200),
                'currency'            => 'USD',
                'processed_at'        => now()->subMinutes(random_int(10, 5000)),
                'client_id'           => $cliente->id,
                'status_id'           => $statusNuevo->id,
                'cancelled_at'        => null,
                'scheduled_for'       => null,
                'agent_id'            => null,
                'deliverer_id'        => null,
            ]);
        }
    }
}
