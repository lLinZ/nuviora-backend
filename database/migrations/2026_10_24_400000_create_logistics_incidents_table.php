<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logística propia, incidencias graves del rider (§14): avería, accidente, emergencia… que le impiden completar la ruta.
 * - logistics_incidents: el reporte del rider (motivo, comentario, GPS y hora, §14.1) y lo que decidió el operador
 *   (seguir o interrumpir, y de dónde recoge el reemplazo, §14.2), con el traspaso (§14.3).
 * - logistics_orders.pickup_rider_id: mientras busca reemplazo, el paquete sigue con ese rider (custodia física real): el
 *   nuevo rider lo recoge donde está él, no en el centro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_order_id')->constrained('logistics_orders')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('center_id')->constrained('logistics_centers');
            $table->foreignId('rider_id')->constrained('users');
            $table->string('type', 40);
            $table->string('comment', 500)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('reported_at');
            $table->string('status', 20); // abierta | continua | interrumpida | cerrada
            $table->string('pickup', 20)->nullable(); // rider | almacen
            $table->timestamp('interrupted_at')->nullable();
            $table->foreignId('interrupted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('replacement_rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result')->nullable();
            $table->timestamps();

            $table->index(['rider_id', 'status']);
        });

        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->foreignId('pickup_rider_id')->nullable()->after('rider_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pickup_rider_id');
        });
        Schema::dropIfExists('logistics_incidents');
    }
};
