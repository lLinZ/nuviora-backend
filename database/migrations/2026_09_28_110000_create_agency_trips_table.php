<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarea 5 (Fran §4-6): una fila por cada carrera o intento de entrega. Se paga cada una, con la tarifa
     * que tenía la agencia ese día, y la semana se corta por trip_date. Cada carrera tiene su ganancia
     * (earnings, role_type agencia); si el Admin la anula, esa ganancia se borra y la carrera queda marcada.
     */
    public function up(): void
    {
        Schema::create('agency_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('users');
            $table->foreignId('deliverer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);                 // normal | reentrega | cambio
            $table->string('result', 20)->nullable();   // entregado | novedad | rechazado | cancelado | otro
            $table->foreignId('result_status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->decimal('price_usd', 10, 2);
            $table->date('trip_date');
            $table->dateTime('started_at');
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('earning_id')->nullable()->constrained('earnings')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'trip_date']);
            $table->index(['order_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_trips');
    }
};
