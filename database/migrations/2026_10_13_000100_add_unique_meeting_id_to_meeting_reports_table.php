<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One official report per meeting, enforced by the database.
 *
 * `meeting_id` is nullable (department "ops" reports have no meeting), and a
 * unique index treats NULLs as distinct, so those rows are unaffected.
 *
 * SAFE ON EXISTING DATA: this never deletes or merges anything. If the table
 * already holds two reports for the same meeting the index cannot be created,
 * so the migration does nothing and logs a warning (it is then recorded as run).
 * The controller still refuses duplicates (row lock + existence check). Find
 * the offenders, resolve them by hand keeping the report the owner chooses,
 * then add the index with:
 *     php artisan meetings:duplicate-reports --create-index
 */return new class extends Migration
{
    private const INDEX = 'meeting_reports_meeting_id_unique';

    public function up(): void
    {
        if (! Schema::hasTable('meeting_reports') || ! Schema::hasColumn('meeting_reports', 'meeting_id')) {
            return;
        }

        $duplicates = DB::table('meeting_reports')
            ->select('meeting_id')
            ->whereNotNull('meeting_id')
            ->groupBy('meeting_id')
            ->havingRaw('count(*) > 1')
            ->count();

        if ($duplicates > 0) {
            Log::warning("meeting_reports: unique(meeting_id) NOT added - {$duplicates} meeting(s) already have more than one report. Run `php artisan meetings:duplicate-reports`.");

            return;
        }

        Schema::table('meeting_reports', function (Blueprint $table) {
            $table->unique('meeting_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('meeting_reports')) {
            return;
        }

        try {
            Schema::table('meeting_reports', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        } catch (\Throwable) {
            // index was never created (duplicates existed) - nothing to drop
        }
    }
};
