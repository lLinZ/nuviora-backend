<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\OrderActivityLog;

$count = OrderActivityLog::where('description', 'like', '%En ruta%')->count();
echo "Total logs for 'En ruta': " . $count . "\n";

$logs = OrderActivityLog::where('description', 'like', '%En ruta%')->orderBy('created_at', 'desc')->take(5)->get();
foreach($logs as $log) {
    echo $log->created_at . " - Order " . $log->order_id . ": " . $log->description . "\n";
}
