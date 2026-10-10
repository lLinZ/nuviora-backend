<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprobantes repetidos (pedido de Fran del 2026-10-09, después del falso positivo de #4910 / #8027): el duplicado lo
 * decide el código por capas, no una coincidencia de la referencia sola.
 * - Huellas del archivo: SHA-256 (el mismo archivo exacto) y una huella perceptual de la imagen (dHash: la misma
 *   captura recomprimida o reenviada, solo como indicio).
 * - En la revisión queda por qué se marcó como duplicado y con qué otro comprobante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->char('file_sha256', 64)->nullable()->after('original_name')->index();
            $table->char('image_dhash', 64)->nullable()->after('file_sha256');
        });
        Schema::table('receipt_checks', function (Blueprint $table) {
            $table->string('duplicate_reason', 40)->nullable()->after('issues');
            $table->unsignedBigInteger('duplicate_of_check_id')->nullable()->after('duplicate_reason')->index();
        });
    }

    public function down(): void
    {
        Schema::table('receipt_checks', function (Blueprint $table) {
            $table->dropIndex(['duplicate_of_check_id']);
            $table->dropColumn(['duplicate_reason', 'duplicate_of_check_id']);
        });
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->dropIndex(['file_sha256']);
            $table->dropColumn(['file_sha256', 'image_dhash']);
        });
    }
};
