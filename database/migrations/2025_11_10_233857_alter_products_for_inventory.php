<?php
// database/migrations/2025_01_01_000800_alter_products_for_inventory.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'sku')) {
                $table->string('sku')->unique()->nullable();
            }
            if (!Schema::hasColumn('products', 'price')) {
                $table->decimal('price', 10, 2)->default(0);
            }
            if (!Schema::hasColumn('products', 'currency')) {
                $table->string('currency', 8)->default('USD');
            }
            if (!Schema::hasColumn('products', 'stock')) {
                $table->integer('stock')->default(0)->after('price');
            }
            if (!Schema::hasColumn('products', 'cost')) {
                $table->decimal('cost', 12, 2)->default(0)->after('stock'); // ya que dijiste "Costo de productos: Sí"
            }
            // image ya la tienes; si no:
            // if (!Schema::hasColumn('products','image')) { $table->string('image')->nullable(); }
        });
    }
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // no hacemos down destructivo
        });
    }
};
