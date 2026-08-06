<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('audit_logs', function (Blueprint $table) {
                if (Schema::hasColumn('audit_logs', 'actor_id')) {
                    $table->renameColumn('actor_id', 'user_id');
                }
                if (Schema::hasColumn('audit_logs', 'actor_name')) {
                    $table->renameColumn('actor_name', 'user_name');
                }
                if (Schema::hasColumn('audit_logs', 'actor_role')) {
                    $table->renameColumn('actor_role', 'user_role');
                }
                if (Schema::hasColumn('audit_logs', 'entity_label')) {
                    $table->renameColumn('entity_label', 'entity_name');
                }
            });
        } else {
            DB::statement('ALTER TABLE audit_logs CHANGE `actor_id`     `user_id`    BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE audit_logs CHANGE `actor_name`   `user_name`  VARCHAR(255) NULL');
            DB::statement('ALTER TABLE audit_logs CHANGE `actor_role`   `user_role`  VARCHAR(255) NULL');
            DB::statement('ALTER TABLE audit_logs CHANGE `entity_label` `entity_name` VARCHAR(255) NULL');
        }

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

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('audit_logs', function (Blueprint $table) {
                if (Schema::hasColumn('audit_logs', 'user_id')) {
                    $table->renameColumn('user_id', 'actor_id');
                }
                if (Schema::hasColumn('audit_logs', 'user_name')) {
                    $table->renameColumn('user_name', 'actor_name');
                }
                if (Schema::hasColumn('audit_logs', 'user_role')) {
                    $table->renameColumn('user_role', 'actor_role');
                }
                if (Schema::hasColumn('audit_logs', 'entity_name')) {
                    $table->renameColumn('entity_name', 'entity_label');
                }
            });
        } else {
            DB::statement('ALTER TABLE audit_logs CHANGE `user_id`    `actor_id`    BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE audit_logs CHANGE `user_name`  `actor_name`  VARCHAR(255) NULL');
            DB::statement('ALTER TABLE audit_logs CHANGE `user_role`  `actor_role`  VARCHAR(255) NULL');
            DB::statement('ALTER TABLE audit_logs CHANGE `entity_name` `entity_label` VARCHAR(255) NULL');
        }
    }
};
