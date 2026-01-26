<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

try {
    if (!Schema::hasColumn('orders', 'change_payment_details')) {
        DB::statement("ALTER TABLE orders ADD COLUMN change_payment_details TEXT NULL");
        echo "Added change_payment_details\n";
    } else {
        echo "change_payment_details already exists\n";
    }

    if (!Schema::hasColumn('orders', 'change_receipt')) {
        DB::statement("ALTER TABLE orders ADD COLUMN change_receipt VARCHAR(255) NULL");
        echo "Added change_receipt\n";
    } else {
        echo "change_receipt already exists\n";
    }

    $migrationName = '2026_01_25_171047_add_change_fields_to_orders_table';
    $exists = DB::table('migrations')->where('migration', $migrationName)->exists();
    if (!$exists) {
        DB::table('migrations')->insert([
            'migration' => $migrationName,
            'batch' => DB::table('migrations')->max('batch') + 1
        ]);
        echo "Marked migration as run\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
