<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

try {
    Schema::create('order_change_details', function (Blueprint $table) {
        $table->id();
        $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
        $table->text('details')->nullable(); // JSON
        $table->string('receipt')->nullable();
        $table->timestamps();
    });
    echo "Created table order_change_details\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
