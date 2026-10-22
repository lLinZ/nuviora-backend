<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logística propia, semana 1, paso S5 (tasks/logistica-propia/plan.md):
     * - logistics_custody_events: quién tiene físicamente el producto en cada momento, almacén → rider al confirmar la
     *   salida (§11), almacén → agencia al entregárselo (§16.2.2 punto 4) y rider → cliente al entregar (punto 5). "No se
     *   debe 'teletransportar' el stock en el sistema sin esta transición" (§14.3).
     * - logistics_novelties: la novedad del rider, que llega primero al operador (§13): tipo, quién la reportó, cuándo,
     *   responsable, comentarios, si se escaló a la vendedora, cuándo se resolvió y cómo.
     */
    public function up(): void
    {
        Schema::create('logistics_custody_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_order_id')->constrained('logistics_orders')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('from_type', 20); // almacen, rider, agencia
            $table->unsignedBigInteger('from_id')->nullable();
            $table->string('to_type', 20);   // rider, agencia, cliente, almacen
            $table->unsignedBigInteger('to_id')->nullable();
            $table->json('items');           // [{product_id, variant_id, quantity, name}]
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('logistics_order_id');
        });

        Schema::create('logistics_novelties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_order_id')->constrained('logistics_orders')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('type', 30);
            $table->text('description')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->useCurrent();
            $table->string('owner', 20)->default('operador'); // operador o ventas (§13: "responsable actual")
            $table->json('comments')->nullable();             // [{at, user_id, name, text}]
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();
            $table->index(['logistics_order_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_novelties');
        Schema::dropIfExists('logistics_custody_events');
    }
};
