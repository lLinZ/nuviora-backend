<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    if (!Schema::hasColumn('orders', 'change_payment_details')) {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('change_payment_details')->nullable();
        });
        echo "Added change_payment_details\n";
    } else {
        echo "change_payment_details already exists\n";
    }

    if (!Schema::hasColumn('orders', 'change_receipt')) {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('change_receipt')->nullable();
        });
        echo "Added change_receipt\n";
    } else {
        echo "change_receipt already exists\n";
    }

    // Mark migration as run to avoid future issues
    $migrationName = '2026_01_25_171047_add_change_fields_to_orders_table';
    $exists = \DB::table('migrations')->where('migration', $migrationName)->exists();
    if (!$exists) {
        \DB::table('migrations')->insert([
            'migration' => $migrationName,
            'batch' => \DB::table('migrations')->max('batch') + 1
        ]);
        echo "Marked migration as run\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
