<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // SCM: métricas del proveedor para el motor de reabastecimiento
            $table->unsignedTinyInteger('lead_time_days')->default(3)->after('cost_usd')
                ->comment('Días que tarda el proveedor en entregar (Lead Time)');
            $table->decimal('defect_percentage', 5, 2)->default(0.00)->after('lead_time_days')
                ->comment('Porcentaje histórico de merma. Ej: 5.00 = 5%');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['lead_time_days', 'defect_percentage']);
        });
    }
};
