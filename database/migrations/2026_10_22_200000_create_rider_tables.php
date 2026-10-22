<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logística propia, semana 1, paso S3 (tasks/logistica-propia/plan.md): el rider propio y su ubicación (§6, §7).
     * - rider_profiles: centro, activo o suspendido por administración (§6.1), franjas (§23), el teléfono al que se le
     *   manda la alarma (R1: Android por ahora), si tiene la ubicación y las notificaciones permitidas, su última
     *   ubicación y el último ETA al centro (para no pedirle a Google lo mismo cada vez).
     * - rider_locations: el historial de ubicaciones que manda la app.
     * - logistics_orders: el tramo centro → cliente, que se calcula una vez por orden (§7.1 punto 3).
     * El rider es un usuario con el rol Repartidor, que ya existe; no tiene botón "Disponible" (§6).
     */
    public function up(): void
    {
        Schema::create('rider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('center_id')->nullable()->constrained('logistics_centers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason')->nullable();
            // Franjas por día ISO, como el horario del centro; null = el horario del centro
            $table->json('schedule')->nullable();
            // FCM: "fid" (Firebase Installation ID, el recomendado) o "token" (el viejo, todavía aceptado)
            $table->string('push_target_type', 10)->nullable();
            $table->text('push_target')->nullable();
            $table->string('platform', 10)->nullable();
            $table->string('app_version', 30)->nullable();
            $table->boolean('notifications_enabled')->nullable();
            $table->string('location_permission', 20)->nullable(); // siempre, en_uso, negado
            $table->timestamp('device_reported_at')->nullable();
            $table->string('push_error')->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->float('last_accuracy')->nullable();
            $table->timestamp('last_location_at')->nullable();
            $table->unsignedInteger('eta_center_seconds')->nullable();
            $table->unsignedInteger('eta_center_meters')->nullable();
            $table->timestamp('eta_computed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['center_id', 'is_active']);
        });

        Schema::create('rider_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->float('accuracy')->nullable();
            $table->float('speed')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'recorded_at']);
        });

        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->unsignedInteger('center_client_seconds')->nullable()->after('overflow_reason');
            $table->unsignedInteger('center_client_meters')->nullable()->after('center_client_seconds');
            $table->timestamp('center_client_at')->nullable()->after('center_client_meters');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->dropColumn(['center_client_seconds', 'center_client_meters', 'center_client_at']);
        });
        Schema::dropIfExists('rider_locations');
        Schema::dropIfExists('rider_profiles');
    }
};
