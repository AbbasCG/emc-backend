<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks how many times the artisan certificates:retry-failed command has
 * attempted to re-queue a certificate. Prevents infinite retry loops when a
 * certificate persistently fails due to a corrupt template or bad data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->unsignedSmallInteger('pdf_command_retry_count')
                ->default(0)
                ->after('pdf_last_error')
                ->comment('Number of times artisan:retry-failed has dispatched a job for this certificate.');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('pdf_command_retry_count');
        });
    }
};
