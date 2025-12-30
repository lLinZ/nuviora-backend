<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Corregir "Cambio de ubicacion" a "Cambio de ubicacion" (asegurar consistencia sin tilde si no se usa)
        // Y asegurar que "Por aprobar cambio de ubicacion" esté bien escrito.
        DB::table('statuses')
            ->where('description', 'Cambio de ubicación')
            ->update(['description' => 'Cambio de ubicacion']);
            
        DB::table('statuses')
            ->where('description', 'Por aprobar cambio de ubicación')
            ->update(['description' => 'Por aprobar cambio de ubicacion']);
    }

    public function down(): void
    {
    }
};
