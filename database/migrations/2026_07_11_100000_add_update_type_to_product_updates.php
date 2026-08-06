<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_updates', function (Blueprint $table) {
            // Primary type — drives form behavior and display badge
            $table->enum('update_type', [
                'information',
                'new_feature',
                'improvement',
                'redesign',
                'bug_fix',
                'announcement',
                'maintenance',
                'security_update',
                'action_required',
                'mandatory_update',
            ])->default('announcement')->after('category');

            // Optional CTA button
            $table->string('cta_label')->nullable()->after('update_type');
            $table->string('cta_url')->nullable()->after('cta_label');
            $table->boolean('cta_external')->default(false)->after('cta_url');

            // Image fields (new_feature, improvement, redesign)
            $table->string('image_url')->nullable()->after('cta_external');
            $table->string('image_before_url')->nullable()->after('image_url');  // redesign: before
            $table->string('image_after_url')->nullable()->after('image_before_url'); // redesign: after

            // Maintenance-specific
            $table->timestamp('maintenance_start')->nullable()->after('image_after_url');
            $table->timestamp('maintenance_end')->nullable()->after('maintenance_start');
            $table->json('affected_services')->nullable()->after('maintenance_end');
            $table->enum('maintenance_severity', ['low', 'medium', 'high', 'critical'])->nullable()->after('affected_services');

            // Action Required + Security specific
            $table->timestamp('due_date')->nullable()->after('maintenance_severity');
            $table->json('assigned_to_roles')->nullable()->after('due_date');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->nullable()->after('assigned_to_roles');
            $table->boolean('requires_acknowledgement')->default(false)->after('priority');

            // Bug Fix
            $table->text('problem_description')->nullable()->after('requires_acknowledgement');
            $table->text('fix_description')->nullable()->after('problem_description');
            $table->string('affected_users')->nullable()->after('fix_description');

            // Related page
            $table->string('related_page_url')->nullable()->after('affected_users');
        });

        // Back-fill: map existing category → update_type
        // feature → new_feature, improvement → improvement, fix → bug_fix,
        // security → security_update, announcement → announcement
        DB::statement("
            UPDATE product_updates SET update_type = CASE category
                WHEN 'feature'      THEN 'new_feature'
                WHEN 'improvement'  THEN 'improvement'
                WHEN 'fix'          THEN 'bug_fix'
                WHEN 'security'     THEN 'security_update'
                ELSE 'announcement'
            END
        ");
    }

    public function down(): void
    {
        Schema::table('product_updates', function (Blueprint $table) {
            $table->dropColumn([
                'update_type', 'cta_label', 'cta_url', 'cta_external',
                'image_url', 'image_before_url', 'image_after_url',
                'maintenance_start', 'maintenance_end', 'affected_services', 'maintenance_severity',
                'due_date', 'assigned_to_roles', 'priority', 'requires_acknowledgement',
                'problem_description', 'fix_description', 'affected_users',
                'related_page_url',
            ]);
        });
    }
};
