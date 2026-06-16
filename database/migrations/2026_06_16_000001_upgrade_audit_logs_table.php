<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL CHANGE COLUMN: renames + preserves all column properties.
        // Any index on actor_id continues to work on user_id after rename.
        DB::statement('ALTER TABLE audit_logs CHANGE `actor_id`     `user_id`    BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE audit_logs CHANGE `actor_name`   `user_name`  VARCHAR(255) NULL');
        DB::statement('ALTER TABLE audit_logs CHANGE `actor_role`   `user_role`  VARCHAR(255) NULL');
        DB::statement('ALTER TABLE audit_logs CHANGE `entity_label` `entity_name` VARCHAR(255) NULL');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('user_email')->nullable()->after('user_name');
            $table->text('description')->nullable()->after('entity_name');
            $table->json('changed_fields')->nullable()->after('new_values');
            $table->string('route', 255)->nullable()->after('user_agent');
            $table->string('method', 10)->nullable()->after('route');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['user_email', 'description', 'changed_fields', 'route', 'method']);
        });

        DB::statement('ALTER TABLE audit_logs CHANGE `user_id`    `actor_id`    BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE audit_logs CHANGE `user_name`  `actor_name`  VARCHAR(255) NULL');
        DB::statement('ALTER TABLE audit_logs CHANGE `user_role`  `actor_role`  VARCHAR(255) NULL');
        DB::statement('ALTER TABLE audit_logs CHANGE `entity_name` `entity_label` VARCHAR(255) NULL');
    }
};
