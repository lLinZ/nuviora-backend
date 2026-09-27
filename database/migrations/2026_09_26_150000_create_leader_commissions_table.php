<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comisión de liderazgo (Fran, segunda ronda §7; spec de la Líder §12.3-12.5).
     * Una fila por cada comisión de vendedora (venta o upsell) de su grupo: guarda la base, el % que
     * tenía la Líder en ese momento y el monto, para que un cambio de % no altere lo ya generado.
     * Si la comisión de la vendedora se borra (se revierte), su fila se borra con ella.
     */
    public function up(): void
    {
        Schema::create('leader_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('earning_id')->unique()->constrained('earnings')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('seller_id')->constrained('users');
            $table->foreignId('leader_id')->constrained('users');
            $table->foreignId('sales_group_id')->constrained('sales_groups');
            $table->decimal('base_usd', 10, 2);
            $table->decimal('pct', 5, 2);
            $table->decimal('amount_usd', 10, 2);
            $table->date('earning_date');
            $table->timestamps();

            $table->index(['leader_id', 'earning_date']);
            $table->index(['sales_group_id', 'earning_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leader_commissions');
    }
};
