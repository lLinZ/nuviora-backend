<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 3 de Fran (2026-10-09): control interno de tarifas de delivery con Google Routes, fases 1 a 3.
 * - Almacén de la agencia con ciudad, link de Maps y coordenadas (§5, §6). Una agencia puede tener varios: ya lo
 *   permite warehouses.user_id.
 * - Coordenadas de la ubicación del cliente y su historial, sin sobrescribir (§7, §19).
 * - Tarifarios por agencia y ciudad, con versiones y rangos (§10 a §13, §46).
 * - La ruta y el costo esperado de cada orden (§15, §46), y qué ruta usó cada carrera (§18).
 * - Auditoría de las acciones sensibles (§40).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('user_id')->constrained('cities')->nullOnDelete();
            $table->text('maps_url')->nullable()->after('location');
            $table->decimal('latitude', 10, 7)->nullable()->after('maps_url');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('location_lat', 10, 7)->nullable()->after('location');
            $table->decimal('location_lng', 10, 7)->nullable()->after('location_lat');
            $table->string('location_status', 16)->nullable()->after('location_lng'); // ok, pending, invalid
            $table->string('location_note', 200)->nullable()->after('location_status');
        });

        Schema::create('order_location_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->text('previous_url')->nullable();
            $table->decimal('previous_lat', 10, 7)->nullable();
            $table->decimal('previous_lng', 10, 7)->nullable();
            $table->text('new_url')->nullable();
            $table->decimal('new_lat', 10, 7)->nullable();
            $table->decimal('new_lng', 10, 7)->nullable();
            $table->string('new_status', 16)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 300)->nullable();
            $table->boolean('after_attempt')->default(false);
            $table->timestamp('changed_at')->useCurrent();
            $table->index(['order_id', 'id']);
        });

        Schema::create('agency_tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['agency_id', 'city_id', 'version']);
            $table->index(['agency_id', 'city_id', 'valid_from']);
        });

        Schema::create('agency_tariff_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_tariff_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->decimal('min_km', 8, 3);
            $table->decimal('max_km', 8, 3)->nullable();      // null: "en adelante" (solo el último)
            $table->decimal('price', 10, 2)->nullable();      // precio del rango
            $table->decimal('price_per_km', 10, 4)->nullable(); // más este precio por cada km sobre min_km (PYP desde 20 km)
            $table->timestamps();
        });

        Schema::create('order_delivery_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->decimal('origin_lat', 10, 7)->nullable();
            $table->decimal('origin_lng', 10, 7)->nullable();
            $table->decimal('destination_lat', 10, 7)->nullable();
            $table->decimal('destination_lng', 10, 7)->nullable();
            $table->text('maps_url')->nullable();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->decimal('distance_km', 10, 3)->nullable();
            $table->date('tariff_date');
            $table->foreignId('agency_tariff_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agency_tariff_range_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('expected_cost', 10, 2)->nullable();
            $table->string('calculation_status', 24)->index();
            $table->string('error', 300)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->string('reason', 40);
            $table->boolean('requires_review')->default(false);
            $table->string('review_note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['order_id', 'is_active']);
        });

        Schema::table('agency_trips', function (Blueprint $table) {
            $table->foreignId('delivery_route_id')->nullable()->after('agency_id')->constrained('order_delivery_routes')->nullOnDelete();
        });

        Schema::create('delivery_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 40);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });

        // La ciudad de cada almacén de agencia, si la agencia atiende una sola (city_agency)
        foreach (DB::table('warehouses')->whereNotNull('user_id')->get(['id', 'user_id']) as $w) {
            $cities = DB::table('city_agency')->where('agency_id', $w->user_id)->pluck('city_id')->unique();
            if ($cities->count() === 1) {
                DB::table('warehouses')->where('id', $w->id)->update(['city_id' => $cities->first()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_audit_logs');
        Schema::table('agency_trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_route_id');
        });
        Schema::dropIfExists('order_delivery_routes');
        Schema::dropIfExists('agency_tariff_ranges');
        Schema::dropIfExists('agency_tariffs');
        Schema::dropIfExists('order_location_history');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['location_lat', 'location_lng', 'location_status', 'location_note']);
        });
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
            $table->dropColumn(['maps_url', 'latitude', 'longitude']);
        });
    }
};
