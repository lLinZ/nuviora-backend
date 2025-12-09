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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('from_warehouse_id')->nullable()->constrained('warehouses')->onDelete('restrict');
            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses')->onDelete('restrict');
            $table->integer('quantity'); // Always positive, represents amount moved
            $table->enum('movement_type', ['transfer', 'in', 'out', 'adjustment']);
            $table->string('reference_type')->nullable(); // e.g., 'order', 'purchase', 'manual'
            $table->unsignedBigInteger('reference_id')->nullable(); // ID of the reference
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Indexes for better query performance
            $table->index(['product_id', 'created_at']);
            $table->index(['from_warehouse_id', 'created_at']);
            $table->index(['to_warehouse_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
