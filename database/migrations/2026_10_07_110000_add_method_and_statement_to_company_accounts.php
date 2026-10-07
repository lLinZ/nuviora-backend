<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Documento de Fran del 2026-10-06 (Módulo 1):
 * - §2: cada método digital está relacionado con su moneda, su cuenta receptora y el extracto donde se verifica, sin
 *   programarlo de forma fija. Las cuentas de "Cuentas bancarias" dicen su método, su moneda y su extracto.
 * - §13: puede haber varios bancos para un mismo método, y cada pago sabe a qué cuenta fue
 *   (receipt_checks.company_account_id).
 * Un extracto (statement_sources) puede servir a varias cuentas: pago móvil y transferencia del mismo banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('currency', 8);
            // Qué columna del archivo es cada dato (fecha, referencia, monto…), guardado la primera vez que se sube
            $table->json('format')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('company_accounts', function (Blueprint $table) {
            $table->string('method', 24)->nullable()->after('icon');
            $table->string('currency', 8)->nullable()->after('method');
            $table->foreignId('statement_source_id')->nullable()->after('currency')->constrained('statement_sources')->nullOnDelete();
        });

        Schema::table('receipt_checks', function (Blueprint $table) {
            $table->foreignId('company_account_id')->nullable()->after('currency')->constrained('company_accounts')->nullOnDelete();
        });

        // Las cuentas de hoy: el método y la moneda salen del nombre (como lo hacía ReceiptChecker) y el extracto,
        // del banco que dicen sus datos. Pago móvil y transferencia del mismo banco comparten el extracto.
        $wallets = ['binance' => ['Binance', 'USDT'], 'zinli' => ['Zinli', 'USD'], 'zelle' => ['Zelle', 'USD'], 'paypal' => ['PayPal', 'USD']];
        foreach (DB::table('company_accounts')->get() as $account) {
            $name = Str::lower(Str::ascii($account->name));
            $method = str_contains($name, 'pago') && str_contains($name, 'movil') ? 'pago_movil'
                : (str_contains($name, 'transfer') ? 'transferencia'
                    : collect(array_keys($wallets))->first(fn ($k) => str_contains($name, $k)));
            if (!$method) {
                continue;
            }
            if (isset($wallets[$method])) {
                [$sourceName, $currency] = $wallets[$method];
            } else {
                $bank = collect(json_decode($account->details ?? '[]', true) ?: [])
                    ->first(fn ($row) => str_contains(Str::lower(Str::ascii((string) ($row['label'] ?? ''))), 'banco'))['value'] ?? null;
                // "Banesco (0134)" → "Banesco"
                $sourceName = trim(preg_replace('/\(.*?\)|\d+/', '', (string) $bank)) ?: $account->name;
                $currency = 'VES';
            }
            $sourceId = DB::table('statement_sources')->where('name', $sourceName)->value('id')
                ?? DB::table('statement_sources')->insertGetId(['name' => $sourceName, 'currency' => $currency, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('company_accounts')->where('id', $account->id)->update(['method' => $method, 'currency' => $currency, 'statement_source_id' => $sourceId]);
        }
    }

    public function down(): void
    {
        Schema::table('receipt_checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_account_id');
        });
        Schema::table('company_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('statement_source_id');
            $table->dropColumn(['method', 'currency']);
        });
        Schema::dropIfExists('statement_sources');
    }
};
