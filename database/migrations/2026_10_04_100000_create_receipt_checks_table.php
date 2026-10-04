<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión de cada comprobante de pago con IA (Fran, 2026-10-03): lo que se leyó de la imagen y si cuadra con
 * los pagos de la orden y las cuentas de la empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_receipt_id')->unique()->constrained('payment_receipts')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // pending, ok, warning, fail, unreadable, error
            $table->string('status', 16)->default('pending')->index();
            // pago_movil, transferencia, binance, zinli, zelle, paypal, efectivo, otro, ilegible
            $table->string('kind', 16)->nullable();
            $table->json('extracted')->nullable();
            $table->json('issues')->nullable();
            // Referencia solo con dígitos, para encontrar el mismo comprobante en otra orden
            $table->string('reference', 64)->nullable()->index();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('response_id', 128)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_checks');
    }
};
