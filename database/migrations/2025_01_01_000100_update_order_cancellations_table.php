<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_cancellations', function (Blueprint $table) {
            $table->dateTime('reviewed_at')->nullable();
            $table->text('response_note')->nullable();
            $table->foreignId('previous_status_id')->nullable()->constrained('statuses')->nullOnDelete();
        });

        // (Opcional recomendado) marca de tiempo de cancelación en orders
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('cancelled_at')->nullable()->after('status_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_cancellations', function (Blueprint $table) {
            $table->dropColumn(['status', 'reviewed_at', 'response_note']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('previous_status_id');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
