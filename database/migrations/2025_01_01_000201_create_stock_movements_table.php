<?php

// database/migrations/2025_01_01_000201_create_stock_movements_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // in | out | adjust
            $table->enum('type', ['in', 'out', 'adjust']);
            $table->integer('quantity'); // cantidad movida (+ para in, - para out, libre para adjust)
            $table->integer('before')->default(0);
            $table->integer('after')->default(0);

            $table->string('note')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
