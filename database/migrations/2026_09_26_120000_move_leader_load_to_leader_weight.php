<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La Líder pasa a tener su % dentro de la lista del grupo, igual que sus vendedoras (Fran, 2026-09-26).
     * Antes era una carga aparte ("el 80 % de lo que recibe una vendedora promedio"), que no se entendía.
     *
     * La carga L con n vendedoras equivale a L / (n + L) de lo que recibe el grupo: 0,80 con 4 vendedoras
     * da 17 %. Si las vendedoras ya tenían %, se reescalan para que todo sume 100.
     */
    public function up(): void
    {
        foreach (DB::table('sales_groups')->get(['id', 'leader_load']) as $group) {
            $open = DB::table('sales_group_members')->where('sales_group_id', $group->id)->whereNull('ended_at');
            $leader = (clone $open)->where('role', 'leader')->first(['id']);
            if (!$leader) {
                continue;
            }
            $sellers = (clone $open)->where('role', 'seller')->orderBy('id')->get(['id', 'weight']);
            $n = $sellers->count();
            $load = (float) $group->leader_load;

            $pct = match (true) {
                $load <= 0 => 0,
                $n === 0 => null,
                default => (int) round(100 * $load / ($n + $load)),
            };
            DB::table('sales_group_members')->where('id', $leader->id)->update(['weight' => $pct]);

            $sum = (float) $sellers->sum('weight');
            if ($pct === null || $n === 0 || $sellers->whereNull('weight')->isNotEmpty() || $sum <= 0) {
                continue;
            }
            $left = 100 - $pct;
            $given = 0.0;
            foreach ($sellers->values() as $i => $seller) {
                $weight = $i === $n - 1 ? round($left - $given, 2) : round($seller->weight * $left / $sum, 2);
                $given += $weight;
                DB::table('sales_group_members')->where('id', $seller->id)->update(['weight' => $weight]);
            }
        }

        Schema::table('sales_groups', function (Blueprint $table) {
            $table->dropColumn('leader_load');
        });
    }

    public function down(): void
    {
        Schema::table('sales_groups', function (Blueprint $table) {
            $table->decimal('leader_load', 4, 2)->default(0.65)->after('name');
        });

        foreach (DB::table('sales_groups')->get(['id']) as $group) {
            $open = DB::table('sales_group_members')->where('sales_group_id', $group->id)->whereNull('ended_at');
            $leader = (clone $open)->where('role', 'leader')->first(['id', 'weight']);
            if (!$leader) {
                continue;
            }
            $n = (clone $open)->where('role', 'seller')->count();
            $pct = $leader->weight === null ? null : (float) $leader->weight;
            $load = match (true) {
                $pct === null => 1.0,
                $pct <= 0 => 0.0,
                $pct >= 100 || $n === 0 => 1.0,
                default => min(99.99, $n * $pct / (100 - $pct)),
            };
            DB::table('sales_groups')->where('id', $group->id)->update(['leader_load' => round($load, 2)]);
            DB::table('sales_group_members')->where('id', $leader->id)->update(['weight' => null]);
        }
    }
};
