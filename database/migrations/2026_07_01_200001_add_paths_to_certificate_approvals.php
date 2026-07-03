<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_approvals', function (Blueprint $table) {
            $table->string('approver_role')->nullable()->after('approved_by');
            $table->string('signature_path')->nullable()->after('approver_role');
            $table->string('stamp_path')->nullable()->after('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_approvals', function (Blueprint $table) {
            $table->dropColumn(['approver_role', 'signature_path', 'stamp_path']);
        });
    }
};
