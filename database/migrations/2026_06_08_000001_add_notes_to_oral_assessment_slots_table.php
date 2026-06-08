<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('oral_assessment_slots', 'notes')) {
            Schema::table('oral_assessment_slots', function (Blueprint $table) {
                $table->text('notes')->nullable()->after('meeting_link');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('oral_assessment_slots', 'notes')) {
            Schema::table('oral_assessment_slots', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }
    }
};
