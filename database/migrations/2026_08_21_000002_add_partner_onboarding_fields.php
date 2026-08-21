<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->foreignId('source_request_id')
                ->nullable()
                ->after('id')
                ->unique()
                ->constrained('partnership_requests')
                ->nullOnDelete();
            $table->string('partnership_type', 50)->nullable()->after('type');
            $table->string('project_scope', 30)->default('EMC_GENERAL')->after('partnership_type');
            $table->string('classification', 30)->nullable()->after('project_scope');
            $table->string('field_of_work')->nullable()->after('classification');
            $table->string('contact_position')->nullable()->after('contact_person');
            $table->string('assigned_to')->nullable()->after('city');
            $table->date('first_contact_date')->nullable()->after('assigned_to');
            $table->date('last_contact_date')->nullable()->after('first_contact_date');
            $table->text('rejection_reason')->nullable()->after('last_contact_date');
            $table->string('attachment_url')->nullable()->after('rejection_reason');
            $table->string('onboarding_status', 30)->default('account_ready')->after('status');
            $table->timestamp('activated_at')->nullable()->after('onboarding_status');
        });

        Schema::table('partnership_requests', function (Blueprint $table) {
            $table->foreignId('converted_partner_id')
                ->nullable()
                ->after('reviewed_by')
                ->constrained('partners')
                ->nullOnDelete();
            $table->foreignId('converted_user_id')
                ->nullable()
                ->after('converted_partner_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('review_notes')->nullable()->after('converted_user_id');
            $table->timestamp('reviewed_at')->nullable()->after('review_notes');
        });
    }

    public function down(): void
    {
        Schema::table('partnership_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_partner_id');
            $table->dropConstrainedForeignId('converted_user_id');
            $table->dropColumn(['review_notes', 'reviewed_at']);
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_request_id');
            $table->dropColumn([
                'partnership_type', 'project_scope', 'classification', 'field_of_work',
                'contact_position', 'assigned_to', 'first_contact_date', 'last_contact_date',
                'rejection_reason', 'attachment_url', 'onboarding_status', 'activated_at',
            ]);
        });
    }
};
