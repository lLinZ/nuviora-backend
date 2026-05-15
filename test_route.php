<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$routes = \Illuminate\Support\Facades\Route::getRoutes();
foreach ($routes as $route) {
    if (strpos($route->uri(), 'inventory/products/') !== false) {
        echo $route->uri() . " -> " . $route->getActionName() . "\n";
    }
}
