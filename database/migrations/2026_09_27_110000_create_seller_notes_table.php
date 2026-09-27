<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notas privadas de la Líder sobre sus vendedoras (spec §13). Las ven la Líder del grupo en el que
     * se escribieron y Administración; nunca las vendedoras ni otras Líderes. Guardan autor y fecha.
     */
    public function up(): void
    {
        Schema::create('seller_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_group_id')->constrained('sales_groups')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['sales_group_id', 'seller_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_notes');
    }
};
