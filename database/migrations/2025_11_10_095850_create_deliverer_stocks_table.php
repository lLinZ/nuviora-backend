<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deliverer_stocks', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->foreignId('deliverer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('qty_assigned')->default(0);
            $table->integer('qty_returned')->default(0);
            $table->timestamps();
            $table->unique(['date', 'deliverer_id', 'product_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('deliverer_stocks');
    }
};
