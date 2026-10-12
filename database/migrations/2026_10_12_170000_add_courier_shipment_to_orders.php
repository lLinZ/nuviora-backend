<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envío por agencia de encomiendas (Fran, audios del 2026-10-12, 10:14 y 10:18). Las órdenes que salen por MRW, Tealca,
 * Zoom… llevan en el link de Maps la oficina de donde sale la encomienda, y aparte los datos que pide la empresa:
 * "cédula, nombre, número de teléfono… creo que solamente esos tres".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('courier_shipment')->default(false)->after('location_note');
            $table->string('courier_recipient_name', 150)->nullable()->after('courier_shipment');
            $table->string('courier_recipient_id_number', 30)->nullable()->after('courier_recipient_name');
            $table->string('courier_recipient_phone', 40)->nullable()->after('courier_recipient_id_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['courier_shipment', 'courier_recipient_name', 'courier_recipient_id_number', 'courier_recipient_phone']);
        });
    }
};
