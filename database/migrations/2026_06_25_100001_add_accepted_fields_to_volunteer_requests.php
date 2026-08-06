<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('volunteer_requests', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('reviewed_at');
            }
            if (! Schema::hasColumn('volunteer_requests', 'accepted_by')) {
                $table->foreignId('accepted_by')->nullable()->after('accepted_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('volunteer_requests', 'converted_by')) {
                $table->foreignId('converted_by')->nullable()->after('converted_member_id')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_requests', function (Blueprint $table) {
            $table->dropForeign(['accepted_by']);
            $table->dropForeign(['converted_by']);
            $table->dropColumn(['accepted_at', 'accepted_by', 'converted_by']);
        });
    }
};
