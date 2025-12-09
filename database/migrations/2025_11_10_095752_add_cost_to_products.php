<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'cost_usd')) {
                $table->decimal('cost_usd', 10, 2)->nullable()->after('price');
            }
        });
    }
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'cost_usd')) {
                $table->dropColumn('cost_usd');
            }
        });
    }
};
