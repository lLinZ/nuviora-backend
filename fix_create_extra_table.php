<?php
use Illuminate\Support\Facades\DB;
try {
    DB::statement("
        CREATE TABLE IF NOT EXISTS order_change_extras (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            change_payment_details TEXT NULL,
            change_receipt VARCHAR(255) NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            UNIQUE KEY order_change_extras_order_id_unique (order_id),
            CONSTRAINT fk_order_change_extras_order_id FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Created table order_change_extras\n";
} catch (\Exception $e) {
    echo "Create failed: " . $e->getMessage() . "\n";
}
