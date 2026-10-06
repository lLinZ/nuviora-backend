<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivos en el chat interno (2026-10-06): un adjunto por mensaje (foto, video, audio, PDF u otro documento),
 * guardado en el disco privado. El texto queda como pie del archivo y puede ir vacío.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('body');
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->string('attachment_mime', 127)->nullable()->after('attachment_name');
            $table->unsignedInteger('attachment_size')->nullable()->after('attachment_mime');
            // image | video | audio | voice | pdf | file: cómo se muestra en el chat
            $table->string('attachment_kind', 16)->nullable()->after('attachment_size');
        });
    }

    public function down(): void
    {
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'attachment_kind']);
        });
    }
};
