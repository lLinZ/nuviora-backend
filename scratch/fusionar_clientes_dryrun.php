<?php
/**
 * SIMULACRO (dry-run) de la fusión de clientes duplicados por teléfono.
 * NO modifica NADA. Solo reporta qué haría la fusión real.
 *
 * Regla: por cada teléfono con varios clientes, SOBREVIVE el más antiguo
 * (menor id, donde ya vive el chat y donde caen las órdenes nuevas).
 * Todo lo de los duplicados se reasignaría a ese sobreviviente.
 *
 * Uso:  php scratch/fusionar_clientes_dryrun.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use Illuminate\Support\Facades\DB;

function last10($phone) {
    return substr(preg_replace('/[^0-9]/', '', (string) $phone), -10);
}

echo "=== SIMULACRO de fusión de clientes (NO modifica nada) ===\n\n";

// 1) Detectar dinámicamente TODAS las tablas con columna client_id
$dbName = DB::getDatabaseName();
$cols = DB::select(
    "SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'client_id'",
    [$dbName]
);
$tablas = array_map(fn($r) => $r->t, $cols);
echo "Tablas que dependen de client_id (detectadas en la base):\n";
foreach ($tablas as $t) echo "  - {$t}\n";
echo "\n";

// 2) Agrupar clientes por teléfono (últimos 10 dígitos)
$mapa = [];
Client::select('id', 'phone')->orderBy('id')->chunk(2000, function ($chunk) use (&$mapa) {
    foreach ($chunk as $c) {
        $p = last10($c->phone);
        if (strlen($p) < 7) continue;
        $mapa[$p][] = (int) $c->id;
    }
});

// 3) Quedarnos con los grupos duplicados
$dups = array_filter($mapa, fn($ids) => count($ids) > 1);

$gruposAfectados = count($dups);
$sobrevivientes  = [];
$victimas        = [];
foreach ($dups as $ids) {
    sort($ids);                       // menor id primero
    $sobrevivientes[] = $ids[0];      // sobrevive el más antiguo
    $victimas = array_merge($victimas, array_slice($ids, 1)); // el resto se fusiona
}

echo "Teléfonos a unificar:                 {$gruposAfectados}\n";
echo "Clientes que SOBREVIVEN:              " . count($sobrevivientes) . "\n";
echo "Clientes duplicados a ELIMINAR:       " . count($victimas) . "\n\n";

// 4) ¿Cuántas filas se moverían en cada tabla?
echo "Filas que se REASIGNARÍAN al cliente sobreviviente (por tabla):\n";
$totalFilas = 0;
foreach ($tablas as $t) {
    $n = 0;
    foreach (array_chunk($victimas, 1000) as $lote) {
        $n += DB::table($t)->whereIn('client_id', $lote)->count();
    }
    $totalFilas += $n;
    echo "  - {$t}: {$n}\n";
}
echo "\nTotal de filas a reasignar: {$totalFilas}\n";

// 5) Ejemplo concreto: mostrar 3 grupos para que veas el antes/después
echo "\n--- EJEMPLOS (primeros 3 teléfonos duplicados) ---\n";
$i = 0;
foreach ($dups as $p10 => $ids) {
    if ($i++ >= 3) break;
    sort($ids);
    $surv = $ids[0];
    $vict = array_slice($ids, 1);
    echo "Teléfono ...{$p10}:\n";
    echo "   SOBREVIVE  -> cliente #{$surv}\n";
    echo "   se fusionan -> #" . implode(', #', $vict) . "\n";
    foreach ($tablas as $t) {
        $cv = DB::table($t)->whereIn('client_id', $vict)->count();
        $cs = DB::table($t)->where('client_id', $surv)->count();
        if ($cv > 0 || $cs > 0) {
            echo "      {$t}: sobreviviente tiene {$cs}, llegarían {$cv} más\n";
        }
    }
    echo "\n";
}

echo "Nada fue modificado. Esto es solo una simulación.\n";
