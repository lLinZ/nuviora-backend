<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarea 3b (Fran §7): una devolución es un reembolso. Se le transfiere el dinero al cliente y el
     * producto se queda con él: no hay orden hija, ni carrera, ni movimiento de stock, y la vendedora
     * conserva su comisión. orders.refunded_usd deja marcada la orden sin consultar esta tabla.
     */
    public function up(): void
    {
        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('amount_usd', 10, 2);
            $table->string('method', 40)->nullable();
            $table->date('refunded_at');
            $table->text('notes')->nullable();
            $table->string('receipt_path')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();

            $table->index('refunded_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('refunded_usd', 10, 2)->default(0)->after('current_total_price');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('refunded_usd');
        });
        Schema::dropIfExists('order_refunds');
    }
};
