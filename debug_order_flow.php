<?php
/**
 * Script de diagnóstico para verificar el sistema de flujo de órdenes
 * Ejecutar: php debug_order_flow.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== DIAGNÓSTICO DE FLUJO DE ÓRDENES ===\n\n";

// 1. Verificar que el archivo de configuración existe
echo "1. Verificando archivo de configuración...\n";
$configFile = config_path('order_flow.php');
if (file_exists($configFile)) {
    echo "   ✅ Archivo existe: {$configFile}\n";
} else {
    echo "   ❌ ARCHIVO NO ENCONTRADO: {$configFile}\n";
    exit(1);
}

// 2. Cargar configuración
echo "\n2. Cargando configuración de flujo...\n";
$flowConfig = config('order_flow');
if (empty($flowConfig)) {
    echo "   ❌ NO SE PUDO CARGAR LA CONFIGURACIÓN\n";
    exit(1);
}

echo "   ✅ Configuración cargada\n";
echo "   Roles configurados: " . implode(', ', array_keys($flowConfig)) . "\n";

// 3. Verificar cada rol
echo "\n3. Verificando configuración por rol:\n";
foreach ($flowConfig as $role => $config) {
    echo "\n   📋 ROL: {$role}\n";
    
    if (isset($config['visible_columns'])) {
        echo "      ✅ Columnas visibles: " . count($config['visible_columns']) . "\n";
    } else {
        echo "      ⚠️  Sin columnas visibles definidas\n";
    }
    
    if (isset($config['transitions'])) {
        echo "      ✅ Transiciones definidas: " . count($config['transitions']) . " estados origen\n";
    } else {
        echo "      ❌ NO HAY TRANSICIONES DEFINIDAS\n";
    }
}

// 4. Verificar ruta API
echo "\n4. Verificando ruta API '/config/flow'...\n";
$routes = app('router')->getRoutes();
$flowRoute = null;
foreach ($routes as $route) {
    if (str_contains($route->uri(), 'config/flow')) {
        $flowRoute = $route;
        break;
    }
}

if ($flowRoute) {
    echo "   ✅ Ruta encontrada: {$flowRoute->uri()}\n";
    echo "      Métodos: " . implode(', ', $flowRoute->methods()) . "\n";
} else {
    echo "   ❌ RUTA NO ENCONTRADA\n";
}

// 5. Simular request para un rol
echo "\n5. Simulando respuesta para rol 'Vendedor'...\n";
$vendedorConfig = config('order_flow.Vendedor');
if ($vendedorConfig) {
    echo "   ✅ Configuración encontrada\n";
    echo "   Columnas visibles: " . json_encode($vendedorConfig['visible_columns'] ?? []) . "\n";
    echo "   Ejemplo de transiciones desde 'Llamado 1':\n";
    $llamado1Transitions = $vendedorConfig['transitions']['Llamado 1'] ?? [];
    echo "      " . json_encode($llamado1Transitions) . "\n";
} else {
    echo "   ❌ No se encontró configuración para Vendedor\n";
}

echo "\n=== FIN DEL DIAGNÓSTICO ===\n";
echo "\n📌 ACCIÓN REQUERIDA:\n";
echo "   1. Si ves algún ❌, revisa el archivo config/order_flow.php\n";
echo "   2. Si todo está ✅ aquí pero no funciona en la app:\n";
echo "      - Ejecuta: php artisan config:clear\n";
echo "      - Ejecuta: php artisan cache:clear\n";
echo "      - Reinicia el servidor de Laravel\n";
echo "      - Verifica que el frontend esté actualizado (git pull + npm run build)\n\n";
