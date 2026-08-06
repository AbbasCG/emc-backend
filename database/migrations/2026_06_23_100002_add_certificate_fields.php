<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->unsignedBigInteger('certificate_template_id')->nullable()->after('metadata');
            $table->foreign('certificate_template_id')->references('id')->on('certificate_templates')->nullOnDelete();

            $table->string('pdf_path')->nullable()->after('certificate_template_id');
            $table->string('recipient_name')->nullable()->after('pdf_path');
            $table->string('recipient_email')->nullable()->after('recipient_name');
            $table->string('certificate_code')->nullable()->unique()->after('recipient_email');
            $table->string('related_type')->nullable()->after('certificate_code');
            $table->unsignedBigInteger('related_id')->nullable()->after('related_type');
            $table->unsignedBigInteger('issued_by')->nullable()->after('related_id');
            $table->foreign('issued_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->after('issued_by');
            $table->text('revoke_reason')->nullable()->after('revoked_at');
            $table->unsignedBigInteger('batch_id')->nullable()->after('revoke_reason');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropForeign(['certificate_template_id']);
            $table->dropForeign(['issued_by']);
            $table->dropColumn([
                'certificate_template_id', 'pdf_path', 'recipient_name',
                'recipient_email', 'certificate_code', 'related_type',
                'related_id', 'issued_by', 'revoked_at', 'revoke_reason', 'batch_id',
            ]);
        });
    }
};
