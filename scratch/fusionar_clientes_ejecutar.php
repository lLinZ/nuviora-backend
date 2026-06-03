<?php
/**
 * FUSIÓN REAL de clientes duplicados por teléfono.
 *
 * SEGUROS:
 *   - Por defecto NO escribe nada (modo simulación). Para aplicar de verdad: --execute
 *   - Se puede limitar a un teléfono:        --phone=584222402406
 *   - Se puede limitar a N grupos (prueba):  --limit=10
 *   - Cada teléfono se procesa en su propia transacción (atómico por grupo).
 *
 * ⚠️ ANTES de correr con --execute, haz un backup de la base (mysqldump).
 *
 * Reglas:
 *   - Sobrevive el cliente más antiguo (menor id).
 *   - orders / whatsapp_messages / order_whats_app_messages -> se reasignan al sobreviviente.
 *   - whatsapp_conversations -> se consolidan en UNA sola conversación 'open' (se borran repetidas;
 *     los mensajes no se pierden porque van por client_id, no por conversación).
 *   - Los clientes duplicados se eliminan al final.
 *   - Se recalcula el bucket del CRM del sobreviviente.
 *
 * Uso:
 *   php scratch/fusionar_clientes_ejecutar.php --phone=584222402406            (simula 1 tel)
 *   php scratch/fusionar_clientes_ejecutar.php --phone=584222402406 --execute  (aplica 1 tel)
 *   php scratch/fusionar_clientes_ejecutar.php --limit=10 --execute            (aplica 10 grupos)
 *   php scratch/fusionar_clientes_ejecutar.php --execute                       (aplica TODO)
 */
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\WhatsappConversation;
use App\Services\ConversationBucketService;
use Illuminate\Support\Facades\DB;

// ---- Parseo de argumentos ----------------------------------------------
$opts = getopt('', ['execute', 'phone::', 'limit::']);
$execute   = isset($opts['execute']);
$onlyPhone = isset($opts['phone']) ? substr(preg_replace('/[^0-9]/', '', $opts['phone']), -10) : null;
$limit     = isset($opts['limit']) ? (int) $opts['limit'] : null;

function last10($phone) {
    return substr(preg_replace('/[^0-9]/', '', (string) $phone), -10);
}

echo $execute
    ? "=== FUSIÓN REAL (--execute): SE MODIFICARÁ LA BASE ===\n\n"
    : "=== SIMULACIÓN (sin --execute): no se modifica nada ===\n\n";

// ---- Tablas dependientes (dinámico) ------------------------------------
$dbName = DB::getDatabaseName();
$cols = DB::select(
    "SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'client_id'",
    [$dbName]
);
$allTables  = array_map(fn($r) => $r->t, $cols);
$convTable  = 'whatsapp_conversations';
$simpleTabs = array_values(array_filter($allTables, fn($t) => $t !== $convTable)); // reasignación directa

echo "Tablas reasignadas directo: " . implode(', ', $simpleTabs) . "\n";
echo "Tabla consolidada:          {$convTable}\n\n";

// ---- Construir grupos duplicados ---------------------------------------
$mapa = [];
Client::select('id', 'phone')->orderBy('id')->chunk(2000, function ($chunk) use (&$mapa) {
    foreach ($chunk as $c) {
        $p = last10($c->phone);
        if (strlen($p) < 7) continue;
        $mapa[$p][] = (int) $c->id;
    }
});
$dups = array_filter($mapa, fn($ids) => count($ids) > 1);
if ($onlyPhone) {
    $dups = array_intersect_key($dups, [$onlyPhone => true]);
    if (!$dups) { echo "El teléfono ...{$onlyPhone} no tiene duplicados. Nada que hacer.\n"; exit; }
}

$procesados = 0; $clientesBorrados = 0; $filasMovidas = 0; $errores = 0;

foreach ($dups as $p10 => $ids) {
    if ($limit !== null && $procesados >= $limit) break;
    sort($ids);
    $survivor = $ids[0];
    $victims  = array_slice($ids, 1);
    $procesados++;

    echo "Tel ...{$p10}: sobrevive #{$survivor}, fusiona #" . implode(', #', $victims) . "\n";

    if (!$execute) {
        foreach ($simpleTabs as $t) {
            $n = DB::table($t)->whereIn('client_id', $victims)->count();
            if ($n) echo "    [sim] {$t}: movería {$n}\n";
        }
        $nc = DB::table($convTable)->whereIn('client_id', $victims)->count();
        if ($nc) echo "    [sim] {$convTable}: consolidaría {$nc}\n";
        $clientesBorrados += count($victims);
        continue;
    }

    try {
        DB::transaction(function () use ($simpleTabs, $convTable, $survivor, $victims, &$filasMovidas) {
            // 1) Reasignar tablas simples
            foreach ($simpleTabs as $t) {
                $filasMovidas += DB::table($t)->whereIn('client_id', $victims)->update(['client_id' => $survivor]);
            }
            // 2) Reasignar conversaciones al sobreviviente
            DB::table('whatsapp_conversations')->whereIn('client_id', $victims)->update(['client_id' => $survivor]);

            // 3) Consolidar: dejar UNA conversación 'open' para el sobreviviente
            $convs = WhatsappConversation::where('client_id', $survivor)
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->get();
            if ($convs->count() > 0) {
                $keeper = $convs->first();
                $keeper->status = 'open';
                $keeper->save();
                $deleteIds = $convs->slice(1)->pluck('id')->all();
                if ($deleteIds) {
                    WhatsappConversation::whereIn('id', $deleteIds)->delete();
                }
            }

            // 4) Borrar clientes duplicados (ya sin filas dependientes)
            Client::whereIn('id', $victims)->delete();
        });

        // 5) Recalcular bucket del CRM del sobreviviente
        ConversationBucketService::recalculate($survivor);

        $clientesBorrados += count($victims);
        echo "    ✅ fusionado\n";
    } catch (\Throwable $e) {
        $errores++;
        echo "    ❌ ERROR (grupo revertido): " . $e->getMessage() . "\n";
    }
}

echo "\n--- RESUMEN ---\n";
echo "Grupos procesados:        {$procesados}\n";
echo "Clientes " . ($execute ? "eliminados" : "a eliminar") . ": {$clientesBorrados}\n";
if ($execute) echo "Filas reasignadas:        {$filasMovidas}\n";
echo "Errores:                  {$errores}\n";
echo $execute ? "\nFusión aplicada.\n" : "\nSimulación. Usa --execute para aplicar (¡con backup antes!).\n";
