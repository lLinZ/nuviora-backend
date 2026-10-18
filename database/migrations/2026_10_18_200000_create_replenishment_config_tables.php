<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 5 de Fran, entrega 2: la configuración del plan de inventario (§5, §6, §12, §13, §17–20, §24, §60–65, §128).
 * - product_inventory_settings: estado comercial (§13) y, si se quiere, cobertura, días de seguridad y % de Sin Stock
 *   propios del producto, que prevalecen sobre los globales (§5, §6, §24). Sin fila = Activo y valores globales.
 * - product_city_availability: producto disponible en una ciudad Sí/No (§12). Sin fila = disponible.
 * - supplier_products: qué proveedor vende qué producto, desde qué ciudad, a qué precio esperado, en cuántos días y a
 *   qué almacén suele llegar (§17–20). Fran los carga en la pantalla (R5).
 * - replenishment_routes + replenishment_route_stops: las rutas logísticas, sus almacenes en el orden sugerido, los días
 *   habituales (opcionales: hoy no hay días fijos, R6) y el lead time (§60–65).
 * Los valores globales (cobertura, seguridad, pesos, umbrales…) van en settings (replenishment.settings).
 * Datos iniciales: la ruta actual Valencia → Maracay → Caracas (§61, 1 día, §64) y Solo Brillo no disponible en Maracay
 * (R7). Todo lo demás arranca Activo y disponible en todas las ciudades (R7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->string('commercial_status', 15)->default('active'); // active, paused, liquidation, discontinued
            $table->decimal('coverage_days', 5, 1)->nullable();
            $table->decimal('safety_days', 5, 1)->nullable();
            $table->unsignedTinyInteger('sin_stock_pct')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('product_city_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'city_id']);
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->decimal('expected_unit_cost_usd', 12, 4)->nullable();
            $table->decimal('lead_time_days', 4, 1)->default(1);
            $table->foreignId('receiving_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_preferred')->default(false);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'product_id']);
        });

        Schema::create('replenishment_routes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('start_city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->decimal('lead_time_days', 4, 1)->default(1);
            $table->json('usual_days')->nullable(); // [1, 3] = lunes y miércoles (ISO); vacío = sale cuando lo dice el Admin (R6)
            $table->decimal('cost_usd', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('replenishment_route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('replenishment_routes')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['route_id', 'warehouse_id']);
        });

        $this->seedInitialData();
    }

    /** §61: la ruta actual (Valencia → Maracay → Caracas) con las agencias de esas ciudades, y R7: Solo Brillo sin Maracay. */
    private function seedInitialData(): void
    {
        $cities = DB::table('cities')->pluck('id', 'name');
        $order = ['Carabobo', 'Aragua', 'Distrito Capital']; // Valencia, Maracay, Caracas
        $present = array_values(array_filter($order, fn ($name) => isset($cities[$name])));
        if ($present) {
            $routeId = DB::table('replenishment_routes')->insertGetId([
                'name' => 'Ruta central (Valencia – Maracay – Caracas)',
                'start_city_id' => $cities['Carabobo'] ?? $cities[$present[0]],
                'lead_time_days' => 1,
                'usual_days' => null,
                'is_active' => true,
                'notes' => 'Trayecto habitual: Valencia → Maracay → Caracas → Maracay → Valencia. Puede saltar Maracay (§61).',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $position = 0;
            foreach ($present as $name) {
                $warehouses = DB::table('warehouses')->where('city_id', $cities[$name])->whereNotNull('user_id')->where('is_active', true)
                    ->where('code', '<>', 'TRANSIT')->orderBy('id')->pluck('id');
                foreach ($warehouses as $warehouseId) {
                    DB::table('replenishment_route_stops')->insert([
                        'route_id' => $routeId, 'warehouse_id' => $warehouseId, 'position' => ++$position,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        if (isset($cities['Aragua'])) {
            foreach (DB::table('products')->where('title', 'like', 'Solo Brillo%')->pluck('id') as $productId) {
                DB::table('product_city_availability')->insert([
                    'product_id' => $productId, 'city_id' => $cities['Aragua'], 'is_available' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_route_stops');
        Schema::dropIfExists('replenishment_routes');
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('product_city_availability');
        Schema::dropIfExists('product_inventory_settings');
    }
};
