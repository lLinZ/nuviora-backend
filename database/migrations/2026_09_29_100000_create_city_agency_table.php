<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tareas 3c y 6 (Fran §11-19): varias agencias por ciudad, cada una con su % (null = parejo) y
     * activa o no. El máximo de órdenes activas es de la agencia (users.max_active_orders, el mismo
     * campo que el de las vendedoras) y vale para todas sus ciudades.
     *  - Las agencias que hoy tiene cada ciudad (cities.agency_id) pasan a esta tabla. cities.agency_id
     *    se conserva como la agencia principal, para lo que todavía la lee.
     *  - orders.agency_locked: la agencia se eligió a mano (o por la orden original, en un cambio) y
     *    el reparto automático no la cambia.
     *  - Estado nuevo "Pendiente de asignación a agencia": todas las agencias de la ciudad están llenas.
     */
    public function up(): void
    {
        Schema::create('city_agency', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('weight', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['city_id', 'agency_id']);
        });

        $now = now();
        $rows = DB::table('cities')->join('users', 'users.id', '=', 'cities.agency_id')->get(['cities.id', 'cities.agency_id'])
            ->map(fn ($c) => ['city_id' => $c->id, 'agency_id' => $c->agency_id, 'weight' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])
            ->all();
        if ($rows) {
            DB::table('city_agency')->insert($rows);
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('agency_locked')->default(false)->after('agency_id');
        });

        if (!DB::table('statuses')->where('description', 'Pendiente de asignación a agencia')->exists()) {
            DB::table('statuses')->insert(['description' => 'Pendiente de asignación a agencia', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('agency_locked');
        });
        Schema::dropIfExists('city_agency');
        // El estado se deja: puede haber órdenes que lo tengan en su historial
    }
};
