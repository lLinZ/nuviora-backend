<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\WhatsappTemplate;

// Create a dummy template instance
$tpl = new WhatsappTemplate();
$tpl->body = "Hola {{1}}, tu pedido {{2}} está en camino.";
$tpl->meta_components = [
    ['type' => 'HEADER', 'text' => "Nuviora Update"]
];

$vars = ["Juan", "Orden #1234"];
$rendered = $tpl->render($vars);

echo "Rendered Text:\n";
echo "--------------\n";
echo $rendered . "\n";
echo "--------------\n";

if (str_contains($rendered, "Juan") && str_contains($rendered, "Orden #1234") && str_contains($rendered, "Nuviora Update")) {
    echo "SUCCESS: Template rendered correctly.\n";
} else {
    echo "FAILURE: Template rendering failed.\n";
}
