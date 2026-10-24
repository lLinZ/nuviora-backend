<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logística propia, pago del rider (§18; R7):
 * - rider_trips: los tramos que se pagan y no nacen de una entrega. Hoy, la ida cuando el rider llegó al cliente y el
 *   pedido no se entregó por causa del cliente (§18.4, R7: "se paga la ida"). Se guarda al momento, con su cálculo, para
 *   que no cambie si la orden vuelve a salir. Las entregas se pagan con su ganancia de repartidor (earnings, R11).
 * - rider_payouts: el cierre diario de honorarios (§18.5): monto, método, fecha y hora y quién pagó, con el detalle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rider_id')->constrained('users');
            $table->foreignId('center_id')->constrained('logistics_centers');
            $table->foreignId('logistics_order_id')->nullable()->constrained('logistics_orders')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('concept', 20); // ida_fallida
            $table->date('trip_date');
            $table->unsignedInteger('meters')->nullable();
            $table->decimal('amount_usd', 10, 2)->nullable(); // null: no tenía tarifario vigente (no se asume, §18.4)
            $table->json('basis')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['rider_id', 'trip_date']);
            $table->unique(['logistics_order_id', 'concept', 'trip_date']);
        });

        Schema::create('rider_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rider_id')->constrained('users');
            $table->foreignId('center_id')->constrained('logistics_centers');
            $table->date('pay_date'); // el día de trabajo que se paga
            $table->decimal('amount_usd', 10, 2);
            $table->string('method', 40);
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->string('note')->nullable();
            $table->json('details')->nullable(); // las carreras que se pagaron, con su cálculo (§26)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['rider_id', 'pay_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_payouts');
        Schema::dropIfExists('rider_trips');
    }
};
