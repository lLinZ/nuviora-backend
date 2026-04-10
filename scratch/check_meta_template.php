<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\WhatsAppService;

$service = new WhatsAppService();
$result = $service->getMetaTemplates();

if (isset($result['data'])) {
    foreach ($result['data'] as $tpl) {
        if ($tpl['name'] === 'pedido_nuevo_basico') {
            echo json_encode($tpl, JSON_PRETTY_PRINT);
            exit;
        }
    }
    echo "Template 'pedido_nuevo_basico' not found in Meta account.\n";
} else {
    echo "Error fetching templates: " . json_encode($result);
}
