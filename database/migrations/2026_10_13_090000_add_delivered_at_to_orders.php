<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha de entrega (Módulo 4 de Fran, §3): el ingreso se reconoce el día en que la orden se entregó, no el día en que se
 * creó. La pone el observer al pasar a "Entregado" y la quita si la orden sale de ese estado. Las ya entregadas toman el
 * último paso a "Entregado" de los dos historiales de estados (en producción, todas las entregadas tienen uno).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('cancelled_at')->index();
        });

        $delivered = DB::table('statuses')->where('description', 'Entregado')->value('id');
        if (!$delivered || !in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::statement(
            'UPDATE orders o JOIN (
                SELECT order_id, MAX(created_at) AS d FROM (
                    SELECT order_id, created_at FROM order_status_logs WHERE to_status_id = ?
                    UNION ALL
                    SELECT order_id, created_at FROM order_tracking_comprehensive_logs WHERE to_status_id = ?
                ) x GROUP BY order_id
            ) l ON l.order_id = o.id
            SET o.delivered_at = l.d
            WHERE o.status_id = ?',
            [$delivered, $delivered, $delivered]
        );
        // Por si alguna no tiene historial: su última modificación
        DB::table('orders')->where('status_id', $delivered)->whereNull('delivered_at')->update(['delivered_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        // El índice se va con la columna
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivered_at');
        });
    }
};
