<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\WhatsappTemplate;

$templates = WhatsappTemplate::all();
foreach ($templates as $t) {
    echo "ID: {$t->id} | Name: '{$t->name}' | Label: '{$t->label}'\n";
}
