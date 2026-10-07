<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documento de Fran del 2026-10-06 (Módulo 1, §9): cuando el cliente paga de más, se guardan por separado el valor
     * de la venta, lo que de verdad se recibió y el excedente. Por método de pago, lo calcula ReceiptChecker con los
     * comprobantes:
     * {"bs": {"metodo": "pago_movil", "moneda": "VES", "venta": 32000, "recibido": 33000, "excedente": 1000}}.
     * No cambia current_total_price ni nada de lo que se usa para las ganancias.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('receipts_summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('receipts_summary');
        });
    }
};
