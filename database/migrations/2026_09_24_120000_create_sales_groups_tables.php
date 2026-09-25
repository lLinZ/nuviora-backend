<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4: grupos de vendedoras.
 * - Solo el Admin crea grupos y elige Líder y vendedoras (Fran, segunda ronda §2).
 * - La membresía guarda desde/hasta: si una vendedora cambia de grupo a mitad de semana, lo anterior
 *   sigue siendo del grupo anterior (spec §2.3). La Líder también es una membresía, con role = leader.
 * - Una persona está en un solo grupo a la vez (§3): lo garantiza el índice único sobre open_user_id,
 *   que solo tiene valor mientras la membresía está abierta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // Porción de la Líder frente a una vendedora estándar (spec §6.2); la fija el Admin.
            $table->decimal('leader_load', 4, 2)->default(0.65);
            // % de comisión de liderazgo de este grupo (Fran §7); se usa en la fase de comisiones.
            $table->decimal('leader_commission_pct', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sales_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_group_id')->constrained('sales_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('role', 10)->default('seller'); // seller | leader
            // % de reparto dentro del grupo; lo pone la Líder (o el Admin). null = sin configurar.
            $table->decimal('weight', 6, 2)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('open_user_id')->nullable()
                ->storedAs('case when ended_at is null then user_id end');
            $table->timestamps();

            $table->unique('open_user_id');
            $table->index(['sales_group_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_group_members');
        Schema::dropIfExists('sales_groups');
    }
};
