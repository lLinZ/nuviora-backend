<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grabaciones y archivos de reuniones de la Líder con su equipo (spec §14): capacitaciones grupales
     * y reuniones uno a uno. Un archivo (guardado en el disco privado) o un enlace, con fecha, tipo y
     * vendedoras. Lo ven la Líder de ese grupo y Administración.
     */
    public function up(): void
    {
        Schema::create('meeting_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_group_id')->constrained('sales_groups')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('meeting_type', 20); // grupal | uno_a_uno
            $table->date('meeting_date');
            $table->string('title', 150);
            $table->text('notes')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('url', 500)->nullable();
            $table->boolean('consent_confirmed')->default(false);
            $table->timestamps();

            $table->index(['sales_group_id', 'meeting_date']);
        });

        Schema::create('meeting_record_sellers', function (Blueprint $table) {
            $table->foreignId('meeting_record_id')->constrained('meeting_records')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['meeting_record_id', 'seller_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_record_sellers');
        Schema::dropIfExists('meeting_records');
    }
};
