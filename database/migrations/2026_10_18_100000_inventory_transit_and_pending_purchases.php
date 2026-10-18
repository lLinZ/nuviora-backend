<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 5 de Fran, entrega 1 (stock real; §14–16, §90–95, §124–126, R1–R4).
 * - Almacén virtual "En tránsito" (tipo TRANSIT): lo que va en camino entre almacenes. Está inactivo y sin usuario, así
 *   que nunca recibe órdenes. Un traslado sale del origen hacia aquí y llega al destino al recibirlo (§15, R2): no se
 *   cuenta dos veces (§124) y los lotes conservan su costo (§126).
 * - inventory_transfers + inventory_transfer_lines: el traslado, con sus estados (§92) y la compra de la que depende,
 *   si la hay (§58, que usa el plan aprobado).
 * - inventory_purchases: la compra "por recibir" (§16, §90, R3). Al crearla no mueve stock ni crea lote, deuda o pago;
 *   al recibirla, con la cantidad y el costo reales, pasa lo de siempre. Las de antes ya estaban recibidas.
 * - inventory_movements.reason: el tipo de ajuste o de salida interna (§94, R4).
 * - inventory_audit_logs: quién cambió qué en el inventario, con antes, después y motivo (§95).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 15)->default('pending'); // pending, preparing, in_transit, received, cancelled
            $table->foreignId('purchase_id')->nullable()->constrained('inventory_purchases')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['to_warehouse_id', 'status']);
        });

        Schema::create('inventory_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('inventory_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            // Los movimientos de cada paso: la recepción toma las capas exactas que creó su salida (§126)
            $table->foreignId('dispatch_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('receive_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('inventory_purchases', function (Blueprint $table) {
            $table->string('status', 12)->default('received')->after('unit_cost_usd'); // pending, received, cancelled
            $table->date('expected_at')->nullable()->after('status');
            $table->dateTime('received_at')->nullable()->after('expected_at');
            $table->unsignedInteger('expected_quantity')->nullable()->after('received_at');
            $table->decimal('expected_total_amount', 14, 2)->nullable()->after('expected_quantity');
            $table->json('expected_variants')->nullable()->after('expected_total_amount');
            $table->dateTime('cancelled_at')->nullable()->after('expected_variants');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable()->after('cancelled_by');

            $table->index(['status', 'expected_at']);
        });
        // La forma de pago se decide al recibir (R3): mientras está por recibir puede no tenerla. Con ALTER directo: change()
        // lee information_schema, que tumba la MariaDB local
        DB::statement('ALTER TABLE inventory_purchases MODIFY payment_type VARCHAR(10) NULL');
        DB::table('inventory_purchases')->whereNull('received_at')->update(['received_at' => DB::raw('purchased_at')]);

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('reason', 30)->nullable()->after('details');
            $table->index(['reason']);
        });

        Schema::create('inventory_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['created_at']);
        });

        // El almacén virtual de lo que va en camino
        $typeId = DB::table('warehouse_types')->where('code', 'TRANSIT')->value('id');
        if (!$typeId) {
            $typeId = DB::table('warehouse_types')->insertGetId([
                'code' => 'TRANSIT',
                'name' => 'En tránsito',
                'description' => 'Mercancía en camino entre almacenes (Módulo 5, §15). No es un lugar físico.',
                'is_physical' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (!DB::table('warehouses')->where('code', 'TRANSIT')->exists()) {
            DB::table('warehouses')->insert([
                'warehouse_type_id' => $typeId,
                'code' => 'TRANSIT',
                'name' => 'En tránsito',
                'description' => 'Traslados que salieron y todavía no se reciben (Módulo 5, §15). Nunca recibe órdenes.',
                'is_active' => false,
                'is_main' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('warehouses')->where('code', 'TRANSIT')->delete();
        DB::table('warehouse_types')->where('code', 'TRANSIT')->delete();
        Schema::dropIfExists('inventory_audit_logs');
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex(['reason']);
            $table->dropColumn('reason');
        });
        Schema::table('inventory_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropIndex(['status', 'expected_at']);
            $table->dropColumn(['status', 'expected_at', 'received_at', 'expected_quantity', 'expected_total_amount', 'expected_variants', 'cancelled_at', 'cancel_reason']);
        });
        Schema::dropIfExists('inventory_transfer_lines');
        Schema::dropIfExists('inventory_transfers');
    }
};
