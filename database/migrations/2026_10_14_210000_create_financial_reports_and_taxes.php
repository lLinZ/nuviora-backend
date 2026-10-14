<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega 4 del Módulo 4 de Fran: los reportes.
 * - financial_reports: un reporte por período (diario D-3 §53, semanal §54, mensual §55 o personalizado §56).
 * - financial_report_versions: cada versión con su contenido entero. Consolidado no es congelado (§20): si una entrega
 *   tardía cambia las cifras, nace otra versión que dice qué cambió (§21), y la anterior queda (§83).
 * - tax_configurations: la estructura para impuestos, tasas y retenciones (§78). Desactivada: no afecta la V1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_reports', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);             // daily, weekly, monthly, custom
            $table->date('period_from');
            $table->date('period_to');
            $table->string('trigger', 10);          // auto, manual
            $table->unsignedInteger('current_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['type', 'period_from', 'period_to']);
        });

        Schema::create('financial_report_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_report_id')->constrained('financial_reports')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 15);           // PROVISIONAL, CONSOLIDADO (§20)
            $table->json('summary');                // las cifras clave, para comparar versiones
            $table->json('changes')->nullable();    // qué cambió respecto de la versión anterior (§21)
            $table->longText('payload');            // el reporte entero (JSON)
            $table->string('reason', 255);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('generated_at');
            $table->string('pdf_path')->nullable();
            $table->dateTime('whatsapp_sent_at')->nullable();
            $table->json('whatsapp_result')->nullable();
            $table->timestamps();
            $table->unique(['financial_report_id', 'version']);
        });

        Schema::create('tax_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('kind', 15);             // tax, rate, withholding (§78)
            $table->decimal('percent', 8, 4)->nullable();
            $table->string('applies_to', 120)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_configurations');
        Schema::dropIfExists('financial_report_versions');
        Schema::dropIfExists('financial_reports');
    }
};
