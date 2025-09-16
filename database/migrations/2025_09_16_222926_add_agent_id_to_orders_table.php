<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            // añadimos la columna sin afectar los registros existentes
            $table->foreignId('agent_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
        });
    }
};
