<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega 3a del Módulo 4 de Fran: las cuentas de la caja y su libro.
 * - financial_accounts (§35, §36): dónde está el dinero, con el saldo empresarial inicial. Las cuentas del Módulo 1
 *   (una por método) apuntan a la suya (R14, §75): Pagomovil y Transferencia van a la misma, la de su extracto.
 * - financial_transactions (§32, §83): un movimiento por cuenta, con monto, moneda, tasa y USD, y su origen. No se
 *   borran: se anulan.
 * - finance.cash_start_date (R14): la caja arranca con el saldo del cierre del miércoles 2026-10-14.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('kind', 20);                        // bank, wallet, cash, agency_cash, other
            $table->string('currency', 5);                     // VES, USD, USDT, EUR
            $table->foreignId('agency_id')->nullable()->constrained('users')->nullOnDelete(); // solo agency_cash
            $table->decimal('opening_balance', 16, 2)->nullable(); // §36: la parte del negocio; null = todavía no se puso
            $table->date('opening_date');
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'agency_id', 'currency']);
        });

        Schema::table('company_accounts', function (Blueprint $table) {
            $table->foreignId('financial_account_id')->nullable()->after('statement_source_id')->constrained('financial_accounts')->nullOnDelete();
        });

        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained('financial_accounts');
            $table->date('occurred_on');
            $table->decimal('amount', 16, 2);                  // con signo, en la moneda de la cuenta
            $table->string('currency', 5);
            $table->decimal('rate', 14, 4)->default(1);        // Bs por USD (1 en USD y USDT, §33)
            $table->decimal('amount_usd', 14, 2);              // con signo
            $table->string('kind', 30);
            $table->string('status', 20)->default('confirmed'); // confirmed, pending (por conciliar), not_found
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('leg', 20)->default('main');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('description', 255)->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['financial_account_id', 'occurred_on']);
            $table->index(['source_type', 'source_id']);
            $table->index(['kind', 'occurred_on']);
        });

        if (!DB::table('settings')->where('key', 'finance.cash_start_date')->exists()) {
            DB::table('settings')->insert(['key' => 'finance.cash_start_date', 'value' => '2026-10-14', 'created_at' => now(), 'updated_at' => now()]);
        }

        // Una cuenta de la caja por extracto + moneda de las cuentas del Módulo 1 (Banesco recibe pago móvil y transferencia)
        $start = DB::table('settings')->where('key', 'finance.cash_start_date')->value('value');
        $wallets = ['binance', 'zinli', 'paypal', 'zelle'];
        $groups = [];
        foreach (DB::table('company_accounts as a')->leftJoin('statement_sources as s', 's.id', '=', 'a.statement_source_id')
            ->select('a.id', 'a.name', 'a.method', 'a.currency', 'a.is_active', 'a.statement_source_id', 's.name as source_name')->orderBy('a.id')->get() as $a) {
            $key = $a->statement_source_id ? "s{$a->statement_source_id}|{$a->currency}" : "a{$a->id}";
            $groups[$key] ??= ['name' => $a->source_name ?: $a->name, 'currency' => $a->currency ?: 'USD',
                'kind' => in_array($a->method, $wallets, true) ? 'wallet' : 'bank', 'active' => false, 'ids' => []];
            $groups[$key]['active'] = $groups[$key]['active'] || (bool) $a->is_active;
            $groups[$key]['ids'][] = $a->id;
        }
        foreach ($groups as $g) {
            $id = DB::table('financial_accounts')->insertGetId(['name' => mb_substr($g['name'], 0, 100), 'kind' => $g['kind'], 'currency' => $g['currency'],
                'opening_date' => $start, 'is_active' => $g['active'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('company_accounts')->whereIn('id', $g['ids'])->update(['financial_account_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
        Schema::table('company_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_account_id');
        });
        Schema::dropIfExists('financial_accounts');
        DB::table('settings')->where('key', 'finance.cash_start_date')->delete();
    }
};
