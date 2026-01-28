<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE daily_agent_rosters DROP INDEX daily_agent_rosters_date_agent_id_unique");
        } catch (\Throwable $e) {
            // ignore
        }
        
        try {
            // Nombre alternativo
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE daily_agent_rosters DROP INDEX roster_date_agent_unique");
        } catch (\Throwable $e) {
            // ignore
        }

        // Schema::table('daily_agent_rosters', function (Blueprint $table) {
        //     // Agregar nueva restricción única incluyendo shop_id
        //     $table->unique(['date', 'agent_id', 'shop_id'], 'roster_date_agent_shop_unique');
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_agent_rosters', function (Blueprint $table) {
            $table->dropUnique('roster_date_agent_shop_unique');
            
            // Nota: Esto podría fallar si hay datos que violen la unicidad al hacer rollback,
            // pero es lo correcto estructuralmente.
            $table->unique(['date', 'agent_id'], 'daily_agent_rosters_date_agent_id_unique');
        });
    }
};
