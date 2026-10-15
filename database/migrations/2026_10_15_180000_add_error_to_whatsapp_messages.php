<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes que Meta no entrega (15/10/2026): Meta acepta el envío y avisa después por el webhook que falló
 * (p. ej. 131042, pagos pendientes). Se guarda el código y el motivo en español para mostrarlo en el chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->unsignedInteger('error_code')->nullable()->after('status');
            $table->string('error_reason', 500)->nullable()->after('error_code');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn(['error_code', 'error_reason']);
        });
    }
};
