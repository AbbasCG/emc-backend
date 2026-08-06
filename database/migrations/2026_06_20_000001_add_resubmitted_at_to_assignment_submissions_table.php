<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('assignment_submissions', 'resubmitted_at')) {
                $table->timestamp('resubmitted_at')->nullable()->after('reviewed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('assignment_submissions', 'resubmitted_at')) {
                $table->dropColumn('resubmitted_at');
            }
        });
    }
};
