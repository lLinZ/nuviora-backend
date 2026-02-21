<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use Illuminate\Support\Facades\DB;
use App\Models\Status;

$startDate = '2026-02-01';
$endDate = '2026-02-21';

echo "--- DIAGNÓSTICO DE ESTADOS ---\n";
$statuses = Status::all();
foreach($statuses as $s) {
    echo "ID: {$s->id} - Desc: '{$s->description}'\n";
}

$statusEntregado = Status::where('description', 'Entregado')->first()?->id;
echo "\nBuscando 'Entregado': ID " . ($statusEntregado ?? 'NO ENCONTRADO') . "\n";

echo "\n--- CONTEO DE ÓRDENES EN RANGO ---\n";
$count = DB::table('orders')->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])->count();
echo "Total órdenes: $count\n";

$entregadas = DB::table('orders')
    ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
    ->where('status_id', $statusEntregado)
    ->count();
echo "Entregadas: $entregadas\n";

echo "\n--- ÚLTIMOS LOGS ---\n";
$logs = DB::table('order_tracking_comprehensive_logs')->orderBy('id', 'desc')->limit(5)->get();
foreach($logs as $l) {
    echo "Log ID: {$l->id} - Seller: {$l->seller_id} - Order: {$l->order_id} - Fecha: {$l->created_at}\n";
}
