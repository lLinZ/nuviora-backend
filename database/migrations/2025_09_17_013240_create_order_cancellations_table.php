<?php
// database/migrations/xxxx_xx_xx_create_order_cancellations_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // quién solicitó cancelación
            $table->text('reason'); // motivo de cancelación
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); // gerente que aprueba/rechaza
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cancellations');
    }
};
