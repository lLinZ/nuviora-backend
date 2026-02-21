<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$from = '2026-02-01';
$to   = '2026-02-21';
$sellerId = 13; // Francisco
$lesdieId = 6;  // Lesdie

// Buscar órdenes que pasaron de Lesdie a Francisco
$reassignments = DB::table('order_tracking_comprehensive_logs as tl1')
    ->join('order_tracking_comprehensive_logs as tl2', function($join) {
        $join->on('tl1.order_id', '=', 'tl2.order_id')
             ->whereRaw('tl2.id > tl1.id');
    })
    ->where('tl1.seller_id', $lesdieId)
    ->where('tl2.seller_id', $sellerId)
    ->selectRaw('tl2.order_id, tl2.created_at as reassigned_at')
    ->orderBy('tl2.created_at')
    ->get();

echo "Total eventos de reasignación Lesdie -> Francisco: " . $reassignments->count() . "\n";

// Agrupar por hora para ver si fue un "volcado" masivo
$grouped = $reassignments->groupBy(function($item) {
    return substr($item->reassigned_at, 0, 13) . ":00"; // Agrupar por hora
});

echo "\nDistribución de las reasignaciones por fecha/hora:\n";
foreach ($grouped as $time => $items) {
    echo "  - $time: " . $items->count() . " órdenes\n";
}

// Ver si hay un comentario o acción específica en los logs de una de estas órdenes
if ($reassignments->count() > 0) {
    $sampleOrderId = $reassignments->first()->order_id;
    echo "\nEjemplo de flujo para la orden ID: $sampleOrderId\n";
    $logs = DB::table('order_tracking_comprehensive_logs as tl')
        ->leftJoin('users as u', 'u.id', '=', 'tl.seller_id')
        ->where('tl.order_id', $sampleOrderId)
        ->select('tl.id', 'u.names', 'tl.created_at')
        ->orderBy('tl.id')
        ->get();
    foreach ($logs as $log) {
        echo "    Log ID: {$log->id} | User: {$log->names} | At: {$log->created_at}\n";
    }
}
