<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Viajes de traslado (Módulo 4 de Fran, entrega 2c; §13, §14, §15, R8). Un viaje agrupa traslados ya hechos, de varios
 * destinos y productos, y se paga una sola vez: mano de obra, combustible, otros y el desgaste del vehículo. Su total se
 * reparte entre todas las unidades movidas en el viaje (landed cost, §14) y se cuenta una sola vez: entra a la ganancia
 * cuando se venden esas unidades, no como gasto del día.
 * - inventory_transfer_trips: los costos en su moneda original, con su tasa y el equivalente en USD (§32, R2), y la regla
 *   de desgaste que se usó (§15). No se borra: se anula con motivo (§83).
 * - inventory_movements.trip_id: los traslados del viaje.
 * - expenses.inventory_trip_id: el gasto "Transporte / logística de inventario" de cada viaje, para saber cuánto se
 *   gasta en transporte cada mes (§13). No cuenta en la rentabilidad del período: ese costo ya va en la logística.
 * - La regla de desgaste es configurable (§15): por defecto el 5 % de mano de obra + combustible + otros (R8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_trips', function (Blueprint $table) {
            $table->id();
            $table->date('trip_date');
            $table->string('description')->nullable(); // la ruta: Valencia → Maracay → Caracas…
            $table->string('currency', 5);
            $table->decimal('rate', 14, 4)->default(1);
            $table->decimal('labor', 14, 2)->default(0);
            $table->decimal('fuel', 14, 2)->default(0);
            $table->decimal('other', 14, 2)->default(0);
            $table->decimal('wear', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('total_usd', 14, 2)->default(0);
            $table->string('wear_mode', 10);           // percent, fixed
            $table->decimal('wear_value', 12, 4);      // el % o los USD fijos que se usaron
            $table->integer('units')->default(0);      // unidades movidas en el viaje
            $table->decimal('unit_cost_usd', 12, 4)->default(0); // total en USD / unidades (§14)
            $table->foreignId('company_account_id')->nullable()->constrained('company_accounts')->nullOnDelete();
            $table->string('receipt_path')->nullable();
            $table->string('notes', 500)->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trip_date']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('trip_id')->nullable()->after('details')->constrained('inventory_transfer_trips')->nullOnDelete();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('inventory_trip_id')->nullable()->after('recurring_expense_id')->constrained('inventory_transfer_trips')->nullOnDelete();
        });

        // La regla de desgaste vive en la configuración, no en el código (§15)
        $now = now();
        foreach (['finance.vehicle_wear_mode' => 'percent', 'finance.vehicle_wear_value' => '5'] as $key => $value) {
            if (!DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_trip_id');
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trip_id');
        });
        Schema::dropIfExists('inventory_transfer_trips');
        DB::table('settings')->whereIn('key', ['finance.vehicle_wear_mode', 'finance.vehicle_wear_value'])->delete();
    }
};
