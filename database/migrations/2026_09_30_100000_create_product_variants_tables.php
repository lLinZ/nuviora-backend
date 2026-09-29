<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarea 4: tallas y variantes.
     *  - product_variants: el catálogo de variantes de cada producto ("Negro / M", "S/M"), con su id de
     *    Shopify cuando lo hay. `key` es el nombre normalizado, para que "S/M" y "s / m" sean la misma.
     *  - inventory_variants: el stock de cada variante en cada almacén, en filas (antes era el JSON
     *    inventories.sizes_stock, que se pisaba con dos ventas a la vez). inventories.quantity sigue siendo
     *    el total del producto; lo que no está repartido por variante se muestra como "sin variante".
     *  - order_products.variant_id e inventory_movements.variant_id: la variante de cada línea y de cada
     *    movimiento. Se conservan `size` (el nombre, para leer) y los JSON viejos, que ya no se escriben.
     * Pasa a variantes las tallas que ya existen: products.available_sizes, las tallas de las órdenes, los
     * movimientos y el desglose de cada almacén.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('title');
            $table->string('key');
            $table->string('option1')->nullable();
            $table->string('option2')->nullable();
            $table->string('option3')->nullable();
            $table->unsignedBigInteger('shopify_variant_id')->nullable()->index();
            $table->string('sku')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'key']);
        });

        Schema::create('inventory_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('defective_stock')->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'variant_id']);
            $table->index(['warehouse_id', 'product_id']);
        });

        Schema::table('order_products', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
        });

        $this->migrateSizes();
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_id');
        });
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_id');
        });
        Schema::dropIfExists('inventory_variants');
        Schema::dropIfExists('product_variants');
    }

    private function migrateSizes(): void
    {
        $now = now();
        $ids = [];
        $variant = function (int $productId, $raw) use (&$ids, $now): ?int {
            $title = trim(preg_replace('/\s+/u', ' ', preg_replace('/^talla\s*:?\s*/iu', '', trim((string) $raw))));
            if ($title === '') {
                return null;
            }
            $key = mb_strtolower(preg_replace('/\s+/u', '', $title));
            $cacheKey = "{$productId}|{$key}";
            if (!isset($ids[$cacheKey])) {
                $id = DB::table('product_variants')->where('product_id', $productId)->where('key', $key)->value('id');
                if (!$id) {
                    $options = array_pad(array_slice(preg_split('/\s+\/\s+/u', $title), 0, 3), 3, null);
                    $id = DB::table('product_variants')->insertGetId([
                        'product_id' => $productId, 'title' => $title, 'key' => $key,
                        'option1' => $options[0], 'option2' => $options[1], 'option3' => $options[2],
                        'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $ids[$cacheKey] = $id;
            }

            return $ids[$cacheKey];
        };

        foreach (DB::table('products')->whereNotNull('available_sizes')->get(['id', 'available_sizes']) as $p) {
            foreach ((array) json_decode((string) $p->available_sizes, true) as $size) {
                if (is_string($size)) {
                    $variant((int) $p->id, $size);
                }
            }
        }

        foreach (['order_products', 'inventory_movements'] as $table) {
            $pairs = DB::table($table)->whereNotNull('size')->where('size', '<>', '')->distinct()->get(['product_id', 'size']);
            foreach ($pairs as $pair) {
                if ($id = $variant((int) $pair->product_id, $pair->size)) {
                    DB::table($table)->where('product_id', $pair->product_id)->where('size', $pair->size)->update(['variant_id' => $id]);
                }
            }
        }

        $rows = DB::table('inventories')->whereNotNull('sizes_stock')->get(['warehouse_id', 'product_id', 'sizes_stock']);
        foreach ($rows as $inv) {
            $stock = [];
            foreach ((array) json_decode((string) $inv->sizes_stock, true) as $size => $qty) {
                if ($id = $variant((int) $inv->product_id, $size)) {
                    $stock[$id] = ($stock[$id] ?? 0) + (int) $qty;
                }
            }
            foreach ($stock as $id => $qty) {
                DB::table('inventory_variants')->insert([
                    'warehouse_id' => $inv->warehouse_id, 'product_id' => $inv->product_id, 'variant_id' => $id,
                    'quantity' => $qty, 'defective_stock' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
};
