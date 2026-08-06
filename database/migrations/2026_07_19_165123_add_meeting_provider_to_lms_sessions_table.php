<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, nullable, fully reversible. No provider integration is claimed —
 * this only stores which provider label to display alongside meeting_url
 * (Ticket 4, Block D3). Existing rows default to null (rendered as "custom").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_sessions', function (Blueprint $table) {
            $table->string('meeting_provider')->nullable()->after('meeting_url');
        });
    }

    public function down(): void
    {
        Schema::table('lms_sessions', function (Blueprint $table) {
            $table->dropColumn('meeting_provider');
        });
    }
};
