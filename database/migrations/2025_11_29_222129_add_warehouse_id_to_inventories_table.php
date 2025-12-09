<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            // Add warehouse_id column as nullable first
            if (!Schema::hasColumn('inventories', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('id')->constrained('warehouses')->onDelete('cascade');
            }
        });

        // Migrate existing inventory records to main warehouse
        $mainWarehouseId = DB::table('warehouses')->where('is_main', true)->value('id');
        
        if ($mainWarehouseId) {
            DB::table('inventories')->update(['warehouse_id' => $mainWarehouseId]);
        }

        Schema::table('inventories', function (Blueprint $table) {
            // Make warehouse_id NOT NULL
            $table->foreignId('warehouse_id')->nullable(false)->change();
            
            // Drop foreign key first to allow dropping the index
            $table->dropForeign(['product_id']);
            
            // Drop old unique constraint on product_id only
            $table->dropUnique(['product_id']);
            
            // Add new unique constraint on warehouse_id + product_id
            $table->unique(['warehouse_id', 'product_id'], 'inventories_warehouse_product_unique');
            
            // Restore foreign key
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            // Drop the composite unique constraint
            $table->dropUnique('inventories_warehouse_product_unique');
            
            // Restore original unique constraint on product_id
            $table->unique(['product_id']);
            
            // Drop warehouse_id foreign key and column
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};
