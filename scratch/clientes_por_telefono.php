<?php
/**
 * DIAGNÓSTICO (solo lectura): muestra cómo están repartidas las órdenes por teléfono.
 *
 * Uso:
 *   php scratch/clientes_por_telefono.php                 -> resumen global de duplicados
 *   php scratch/clientes_por_telefono.php 584222402406    -> detalle de UN teléfono
 *
 * NO modifica nada. Solo lee y reporta.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\Order;

function last10($phone) {
    return substr(preg_replace('/[^0-9]/', '', (string) $phone), -10);
}

$arg = $argv[1] ?? null;

if ($arg) {
    // ---- DETALLE DE UN TELÉFONO ----------------------------------------
    $p10 = last10($arg);
    echo "=== Teléfono terminado en: {$p10} ===\n\n";

    $clients = Client::where('phone', 'like', "%{$p10}")->orderBy('created_at')->get();
    echo "Registros de CLIENTE con este teléfono: " . $clients->count() . "\n";
    if ($clients->count() > 1) {
        echo "  ⚠️  HAY DUPLICADOS: el mismo teléfono está dividido en " . $clients->count() . " clientes.\n";
    }
    echo str_repeat('-', 70) . "\n";

    $totalOrders = 0;
    foreach ($clients as $c) {
        $orders = Order::where('client_id', $c->id)->with('status')->orderBy('created_at')->get();
        $totalOrders += $orders->count();
        echo "Cliente #{$c->id}  |  {$c->first_name} {$c->last_name}  |  customer_id={$c->customer_id}  |  creado {$c->created_at}\n";
        echo "  Órdenes: " . $orders->count() . "\n";
        foreach ($orders as $o) {
            $st = $o->status ? $o->status->description : 'N/A';
            echo "    - Orden {$o->order_number} (id {$o->id})  |  {$st}  |  {$o->current_total_price} {$o->currency}  |  {$o->created_at}\n";
        }
        echo "\n";
    }
    echo str_repeat('-', 70) . "\n";
    echo "TOTAL de órdenes de esta persona: {$totalOrders} (repartidas en {$clients->count()} cliente(s))\n";
    if ($clients->count() > 1) {
        echo "👉 Al unificar por teléfono, las {$totalOrders} órdenes quedarían bajo UN solo cliente.\n";
    }
    exit;
}

// ---- RESUMEN GLOBAL DE DUPLICADOS --------------------------------------
echo "=== RESUMEN GLOBAL: clientes duplicados por teléfono ===\n\n";

$mapa = [];   // last10 => [client_ids]
$sinTel = 0;
Client::select('id', 'phone')->chunk(2000, function ($chunk) use (&$mapa, &$sinTel) {
    foreach ($chunk as $c) {
        $p = last10($c->phone);
        if (strlen($p) < 7) { $sinTel++; continue; } // teléfono inválido/vacío
        $mapa[$p][] = $c->id;
    }
});

$totalTelefonos = count($mapa);
$duplicados = array_filter($mapa, fn($ids) => count($ids) > 1);
$clientesSobrantes = array_sum(array_map(fn($ids) => count($ids) - 1, $duplicados));

echo "Teléfonos únicos:                 {$totalTelefonos}\n";
echo "Teléfonos con MÁS de un cliente:  " . count($duplicados) . "\n";
echo "Clientes 'sobrantes' (duplicados): {$clientesSobrantes}\n";
echo "Clientes sin teléfono válido:      {$sinTel}\n\n";

if (count($duplicados) > 0) {
    // Ordenar por cantidad de duplicados, mostrar top 15
    uasort($duplicados, fn($a, $b) => count($b) <=> count($a));
    echo "Top teléfonos más duplicados (revisa con: php scratch/clientes_por_telefono.php <telefono>):\n";
    $i = 0;
    foreach ($duplicados as $p10 => $ids) {
        if ($i++ >= 15) break;
        $ordenes = Order::whereIn('client_id', $ids)->count();
        echo "  ...{$p10}  ->  " . count($ids) . " clientes, {$ordenes} órdenes en total\n";
    }
}
echo "\nNada fue modificado. Esto es solo un reporte.\n";
