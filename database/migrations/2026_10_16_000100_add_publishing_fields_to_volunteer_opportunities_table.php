<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing support for volunteer opportunities (additive, nullable, no data change).
 *
 * `status` already exists (draft | published | archived); this adds the application deadline,
 * a human duration, who published it and when it was closed. Existing draft rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volunteer_opportunities', function (Blueprint $table) {
            $table->string('duration', 100)->nullable()->after('availability');
            $table->date('closes_at')->nullable()->after('start_date');
            $table->foreignId('published_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('published_at');

            $table->index(['status', 'closes_at'], 'volunteer_opportunities_public_idx');
        });
    }

    public function down(): void
    {
        Schema::table('volunteer_opportunities', function (Blueprint $table) {
            $table->dropIndex('volunteer_opportunities_public_idx');
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn(['duration', 'closes_at', 'closed_at']);
        });
    }
};
