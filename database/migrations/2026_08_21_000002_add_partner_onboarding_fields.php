<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // project_scope/classification/field_of_work/contact_position/assigned_to/
        // first_contact_date/last_contact_date/rejection_reason/attachment_url were
        // already added by 2026_08_16_015310_add_crm_fields_to_partners_table — only
        // add the columns genuinely new to this migration to avoid a duplicate-column
        // error when both run.
        Schema::table('partners', function (Blueprint $table) {
            $table->foreignId('source_request_id')
                ->nullable()
                ->after('id')
                ->unique()
                ->constrained('partnership_requests')
                ->nullOnDelete();
            $table->string('partnership_type', 50)->nullable()->after('type');
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
            $table->dropColumn(['partnership_type', 'onboarding_status', 'activated_at']);
        });
    }
};
