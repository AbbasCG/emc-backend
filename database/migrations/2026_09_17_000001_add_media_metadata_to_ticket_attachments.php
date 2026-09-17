<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket attachments stored no MIME type and no size.
 *
 * Both are needed by the UI: the detail page must decide whether a row is an
 * inline-previewable image before fetching it, and must show a human size
 * ("340 KB") without downloading the file to measure it.
 *
 * Both columns are NULLABLE on purpose. Historical rows keep NULL, and the
 * download endpoint continues to derive the real type by sniffing the file on
 * disk - so existing attachments stay previewable with no backfill and without
 * touching a single stored file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->string('mime_type', 120)->nullable()->after('file_type');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->dropColumn(['mime_type', 'file_size']);
        });
    }
};
