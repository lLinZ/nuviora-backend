<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete(); // Destino de recepción
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('reference_number')->unique(); // OC-2026-001
            $table->enum('status', [
                'draft',        // Borrador
                'sent',         // Enviada al proveedor
                'confirmed',    // Confirmada por el proveedor
                'partial',      // Recibida parcialmente
                'received',     // Recibida completa
                'cancelled',    // Cancelada
            ])->default('draft');
            $table->date('expected_at')->nullable();   // Fecha estimada de llegada
            $table->timestamp('received_at')->nullable(); // Fecha real de recepción completa
            $table->decimal('total_usd', 12, 2)->default(0);
            $table->decimal('total_ves', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'supplier_id']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
