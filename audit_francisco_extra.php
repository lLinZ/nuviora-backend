<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$from = '2026-02-01';
$to   = '2026-02-21';
$sellerId = 13; // Francisco

// 1. Obtener los IDs de las 10 que son suyas (primer asignado)
$propiasIds = DB::table('order_tracking_comprehensive_logs as tl')
    ->join('orders as o', 'o.id', '=', 'tl.order_id')
    ->whereBetween('o.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
    ->whereRaw('tl.id = (
        SELECT MIN(tl2.id) FROM order_tracking_comprehensive_logs tl2
        WHERE tl2.order_id = tl.order_id
          AND tl2.seller_id IN (SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE description = "Vendedor"))
    )')
    ->where('tl.seller_id', $sellerId)
    ->pluck('o.id')
    ->toArray();

// 2. Obtener TODAS las órdenes únicas donde él aparece en el log
$todasIds = DB::table('order_tracking_comprehensive_logs as tl')
    ->join('orders as o', 'o.id', '=', 'tl.order_id')
    ->whereBetween('o.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
    ->where('tl.seller_id', $sellerId)
    ->distinct()
    ->pluck('o.id')
    ->toArray();

// 3. Las reasignadas son las que están en todas pero no en propias
$reasignadasIds = array_diff($todasIds, $propiasIds);

echo "Total únicas que tocó Francisco: " . count($todasIds) . "\n";
echo "Propias (primero él): " . count($propiasIds) . "\n";
echo "Reasignadas (de otras): " . count($reasignadasIds) . "\n\n";

// 4. Analizar el estado ACTUAL de esas 72 reasignadas
$statusResumen = DB::table('orders as o')
    ->join('statuses as s', 's.id', '=', 'o.status_id')
    ->whereIn('o.id', $reasignadasIds)
    ->selectRaw('s.description as status_name, COUNT(*) as count')
    ->groupBy('s.description')
    ->get();

echo "ESTADO ACTUAL de las " . count($reasignadasIds) . " reasignadas:\n";
foreach ($statusResumen as $st) {
    echo "  - {$st->status_name}: {$st->count}\n";
}

// 5. ¿Quién las tiene AHORA?
$agenteActual = DB::table('orders as o')
    ->leftJoin('users as u', 'u.id', '=', 'o.agent_id')
    ->whereIn('o.id', $reasignadasIds)
    ->selectRaw('u.names as agent_name, COUNT(*) as count')
    ->groupBy('u.names')
    ->get();

echo "\n¿Quién es el dueño ACTUAL de esas " . count($reasignadasIds) . " reasignadas?\n";
foreach ($agenteActual as $ag) {
    $name = $ag->agent_name ?? 'SIN AGENTE';
    echo "  - {$name}: {$ag->count}\n";
}
