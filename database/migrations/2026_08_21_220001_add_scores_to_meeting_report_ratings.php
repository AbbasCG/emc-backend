<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // من معيار واحد إلى عشرة معايير لكل حاضر: scores يحمل {criterion: 1..10}
        // وavg_score متوسطها — يبقى عمود rating القديم للتوافق الخلفي.
        Schema::table('meeting_report_ratings', function (Blueprint $table) {
            $table->json('scores')->nullable()->after('rating');
            $table->decimal('avg_score', 4, 1)->nullable()->after('scores');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_report_ratings', function (Blueprint $table) {
            $table->dropColumn(['scores', 'avg_score']);
        });
    }
};
