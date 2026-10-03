<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Validación del vuelto de la agencia cuando el cliente pagó una parte en efectivo y otra digital
 * (Fran, 2026-10-03). Se guarda contra qué pagos y montos se validó: si cambian, hay que validar otra vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_change_extras', function (Blueprint $table) {
            $table->foreignId('change_approved_by')->nullable()->after('change_receipt')->constrained('users')->nullOnDelete();
            $table->timestamp('change_approved_at')->nullable()->after('change_approved_by');
            $table->string('change_approved_signature', 64)->nullable()->after('change_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_change_extras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('change_approved_by');
            $table->dropColumn(['change_approved_at', 'change_approved_signature']);
        });
    }
};
