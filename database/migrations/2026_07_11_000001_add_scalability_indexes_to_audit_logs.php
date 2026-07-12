<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Existing indexes (from prior migrations, do NOT re-add):
    //   audit_logs_user_id_index         (user_id)
    //   audit_logs_action_index          (action)
    //   audit_logs_entity_type_index     (entity_type)
    //   audit_logs_entity_type_entity_id_index  (entity_type, entity_id)
    //   audit_logs_created_at_index      (created_at)
    //
    // Adding only missing composite indexes that support the paginated query patterns.

    public function up(): void
    {
        $existing = $this->existingIndexNames();

        Schema::table('audit_logs', function (Blueprint $table) use ($existing) {
            // Composite: user + time — for "filter by user, order by date" queries
            if (! in_array('al_user_created', $existing, true)) {
                $table->index(['user_id', 'created_at'], 'al_user_created');
            }

            // Composite: action + time — for "filter by action, order by date" queries
            if (! in_array('al_action_created', $existing, true)) {
                $table->index(['action', 'created_at'], 'al_action_created');
            }

            // Composite: entity_type + time — for entity-type filter queries
            if (! in_array('al_entity_created', $existing, true)) {
                $table->index(['entity_type', 'created_at'], 'al_entity_created');
            }

            // Single: method — for HTTP method filter
            if (! in_array('al_method', $existing, true)) {
                $table->index('method', 'al_method');
            }

            // Single: user_role — for role filter
            if (! in_array('al_user_role', $existing, true)) {
                $table->index('user_role', 'al_user_role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            foreach (['al_user_created', 'al_action_created', 'al_entity_created', 'al_method', 'al_user_role'] as $idx) {
                try {
                    $table->dropIndex($idx);
                } catch (\Throwable) {
                }
            }
        });
    }

    private function existingIndexNames(): array
    {
        $rows = DB::select('SHOW INDEX FROM audit_logs');

        return array_unique(array_map(fn ($r) => $r->Key_name, $rows));
    }
};
