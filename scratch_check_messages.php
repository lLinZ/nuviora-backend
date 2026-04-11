<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\WhatsappMessage;
use Carbon\Carbon;

$today = Carbon::today();
$count = WhatsappMessage::where('created_at', '>=', $today)->count();
echo "Messages today: " . $count . "\n";

$last5 = WhatsappMessage::orderBy('created_at', 'desc')->limit(5)->get();
foreach ($last5 as $m) {
    echo "ID: {$m->id}, Body: " . substr($m->body, 0, 50) . "..., Client: {$m->client_id}, Created: {$m->created_at}\n";
}
