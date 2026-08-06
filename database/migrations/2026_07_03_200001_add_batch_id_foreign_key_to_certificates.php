<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the missing foreign key constraint for certificates.batch_id.
 *
 * The column and its index were created in 2026_06_23_100002_add_certificate_fields.php
 * but the FK constraint was accidentally omitted. Orphaned batch_id values are cleaned
 * up first to avoid constraint violations on existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Null out any batch_id values that reference a non-existent batch
        // to prevent FK violation on existing data.
        \Illuminate\Support\Facades\DB::statement(
            'UPDATE certificates
             SET batch_id = NULL
             WHERE batch_id IS NOT NULL
               AND batch_id NOT IN (SELECT id FROM certificate_generation_batches)'
        );

        Schema::table('certificates', function (Blueprint $table) {
            $table->foreign('batch_id')
                ->references('id')
                ->on('certificate_generation_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
        });
    }
};
