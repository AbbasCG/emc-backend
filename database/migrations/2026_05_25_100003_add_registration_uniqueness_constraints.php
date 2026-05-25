<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Check for duplicate authenticated registrations (user_id NOT NULL) ──
        $authDuplicates = DB::table('registrations')
            ->select('user_id', 'course_id', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('user_id')
            ->whereNull('deleted_at')
            ->groupBy('user_id', 'course_id')
            ->having('cnt', '>', 1)
            ->get();

        if ($authDuplicates->isNotEmpty()) {
            $details = $authDuplicates->map(fn ($r) => "user:{$r->user_id} course:{$r->course_id} ({$r->cnt}x)")->implode(', ');
            throw new \RuntimeException(
                "Cannot add unique constraint — duplicate (user_id, course_id) registrations found: {$details}. "
                . "Resolve these duplicates manually, then re-run the migration."
            );
        }

        // ── Check for duplicate guest registrations (user_id NULL, same email+course) ──
        $guestDuplicates = DB::table('registrations')
            ->select('email', 'course_id', DB::raw('COUNT(*) as cnt'))
            ->whereNull('user_id')
            ->whereNull('deleted_at')
            ->groupBy('email', 'course_id')
            ->having('cnt', '>', 1)
            ->get();

        if ($guestDuplicates->isNotEmpty()) {
            $details = $guestDuplicates->map(fn ($r) => "{$r->email} course:{$r->course_id} ({$r->cnt}x)")->implode(', ');
            throw new \RuntimeException(
                "Cannot add unique constraint — duplicate guest (email, course_id) registrations found: {$details}. "
                . "Resolve these duplicates manually, then re-run the migration."
            );
        }

        Schema::table('registrations', function (Blueprint $table) {
            // MySQL treats NULLs as distinct in UNIQUE indexes, so this index only
            // prevents two rows where BOTH user_id and course_id are non-null and identical.
            // Guest rows (user_id = NULL) are not affected.
            if (! $this->hasIndex('registrations', 'registrations_user_course_unique')) {
                $table->unique(['user_id', 'course_id'], 'registrations_user_course_unique');
            }

            // Separate index for fast guest duplicate lookup in app-level checks
            if (! $this->hasIndex('registrations', 'registrations_email_course_index')) {
                $table->index(['email', 'course_id'], 'registrations_email_course_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropUnique('registrations_user_course_unique');
            $table->dropIndex('registrations_email_course_index');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
            return count($indexes) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
