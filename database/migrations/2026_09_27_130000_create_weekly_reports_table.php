<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reporte semanal de la Líder (spec §16). Lo numérico se arma solo cada vez que se abre; aquí se
     * guarda solo lo que escribe la Líder, una fila por grupo y semana (lunes).
     */
    public function up(): void
    {
        Schema::create('weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_group_id')->constrained('sales_groups')->cascadeOnDelete();
            $table->date('week_start');
            $table->text('problems')->nullable();
            $table->text('actions')->nullable();
            $table->text('coaching')->nullable();
            $table->text('recommendations')->nullable();
            $table->text('product_issues')->nullable();
            $table->text('decisions_needed')->nullable();
            $table->text('next_priorities')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sales_group_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_reports');
    }
};
