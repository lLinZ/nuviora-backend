<?php
/**
 * Recalcula el conversation_bucket del CRM para los client_id que le pases.
 * Útil para sobrevivientes de una fusión que no alcanzaron a recalcular.
 *
 * Uso:  php scratch/recalcular_bucket.php 148 162 164 199
 */
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ConversationBucketService;

$ids = array_slice($argv, 1);
if (!$ids) { echo "Uso: php scratch/recalcular_bucket.php <client_id> [<client_id> ...]\n"; exit; }

foreach ($ids as $id) {
    $id = (int) $id;
    try {
        $bucket = ConversationBucketService::recalculate($id);
        echo "  ✅ Cliente #{$id} -> bucket '{$bucket}'\n";
    } catch (\Throwable $e) {
        echo "  ❌ Cliente #{$id}: " . $e->getMessage() . "\n";
    }
}
echo "Listo.\n";
