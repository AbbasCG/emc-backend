<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: Ensure activity_logs has all required audit fields.
 *
 * If activity_logs was already created with the Phase 3 migration,
 * this adds missing columns. If the table does not exist yet, creates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('action');
                $table->string('entity_type')->nullable()->index();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();

                $table->index('created_at');
            });

            return;
        }

        // Add missing columns to existing table
        Schema::table('activity_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('activity_logs', 'entity_type')) {
                $table->string('entity_type')->nullable()->after('action');
            }
            if (! Schema::hasColumn('activity_logs', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            }
            if (! Schema::hasColumn('activity_logs', 'old_values')) {
                $table->json('old_values')->nullable()->after('entity_id');
            }
            if (! Schema::hasColumn('activity_logs', 'new_values')) {
                $table->json('new_values')->nullable()->after('old_values');
            }
        });
    }

    public function down(): void
    {
        // Do not drop the table in rollback — too destructive
    }
};
