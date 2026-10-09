<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integración de Meta Ads (documento de Fran del 2026-10-08, Módulo 2), conexiones y cuentas:
 * - meta_connections: cada conexión (BM, System User o token) con su credencial cifrada (§3, §4).
 * - meta_ad_accounts: las cuentas publicitarias que trae cada conexión y si se sincronizan (§5, §6).
 * - meta_sync_logs: cada sincronización, con inicio, final, estado, error y registros procesados (§49, §55).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('business_id', 32)->nullable();
            // Cifrados con la APP_KEY (cast encrypted): nunca en texto plano ni hacia el navegador (§4)
            $table->text('access_token');
            $table->text('app_secret')->nullable();
            // Quién es el token según Meta (GET /me) y qué permisos tiene (GET /me/permissions)
            $table->string('meta_user_id', 32)->nullable();
            $table->string('meta_user_name')->nullable();
            $table->json('scopes')->nullable();
            // pending: sin sincronizar todavía; ok: la última vuelta salió bien; error: la última falló (§55)
            $table->string('status', 16)->default('pending');
            $table->json('available_accounts')->nullable(); // §3: "cuentas publicitarias disponibles"
            $table->timestamp('accounts_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable(); // §3: "última sincronización correcta"
            $table->string('last_error_kind', 24)->nullable();
            $table->text('last_error')->nullable();           // §3: "último error"
            $table->timestamp('last_error_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('meta_ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_connection_id')->constrained()->cascadeOnDelete();
            $table->string('meta_id', 32)->unique(); // account_id de Meta, sin "act_" (§6: Meta Ad Account ID)
            $table->string('name')->nullable();
            $table->string('currency', 8)->nullable();       // §6: se guarda desde Meta, no se escribe en el código
            $table->string('timezone_name', 64)->nullable();
            $table->unsignedSmallInteger('account_status')->nullable(); // 1 activa, 2 desactivada… (§6: estado)
            $table->boolean('is_active')->default(false);    // §5: se sincroniza o no en nuestro sistema
            $table->timestamp('activated_at')->nullable();
            $table->date('history_from')->nullable();         // primer día importado (§8)
            $table->date('backfill_from')->nullable();        // desde qué día se quiere el histórico (§8: 30 días o más)
            $table->timestamp('presets_synced_at')->nullable(); // última vez que se pidieron los rangos fijos (§57)
            $table->timestamp('first_synced_at')->nullable(); // §6
            $table->timestamp('last_synced_at')->nullable();  // §6
            $table->timestamp('last_seen_at')->nullable();    // última vez que la conexión la listó
            $table->string('last_error_kind', 24)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });

        Schema::create('meta_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_connection_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // scheduled, manual, recheck, backfill, initial
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 16)->default('running'); // running, ok, partial, error
            $table->string('error_kind', 24)->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('records')->default(0); // registros procesados
            $table->unsignedInteger('calls')->default(0);   // llamadas a Meta
            $table->json('details')->nullable();            // resumen por cuenta
            $table->timestamps();
            $table->index(['meta_connection_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_sync_logs');
        Schema::dropIfExists('meta_ad_accounts');
        Schema::dropIfExists('meta_connections');
    }
};
