<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3+4 of the TeamProfile/TeamMember identity correction — enforces
 * "one User = one TeamProfile = at most one operational TeamMember" at the
 * database level. Safe to run only because:
 *
 *   1. `php artisan team-profiles:audit-duplicates` confirmed zero users
 *      with more than one TeamProfile.
 *   2. `php artisan team-members:backfill-profile-link` confirmed zero
 *      team_members rows needing (or unable to receive) a team_profile_id.
 *
 * This migration re-verifies both conditions itself before touching any
 * constraint, and aborts loudly (no schema change at all) if either has
 * drifted since those commands were run — it never silently proceeds on
 * unresolved data.
 *
 * ROOT CAUSE OF THE FIRST FAILED RUN (SQLSTATE 1553): the legacy
 * `team_members_user_id_department_id_unique` composite was the ONLY index
 * covering `user_id` — MySQL was silently using it to satisfy the
 * `user_id` foreign key's indexing requirement, since no standalone index on
 * `user_id` was ever created (the original migration relied on the
 * composite unique instead of an explicit FK-supporting index). Dropping
 * that composite therefore left the `user_id` FK without ANY supporting
 * index, which MySQL refuses. Fix: create a standalone, non-unique index on
 * `user_id` FIRST, so the FK always has a valid supporting index, and only
 * THEN drop the now-redundant composite unique.
 *
 * ROOT CAUSE OF THE SECOND FAILED RUN (SQLSTATE 1830): the prior migration
 * (2026_08_29_000002) created `team_profile_id` as `nullable()->nullOnDelete()`
 * — i.e. its FK's delete rule is `SET NULL`. MySQL refuses to make a column
 * `NOT NULL` while a `SET NULL` foreign key still references it (a `SET
 * NULL` FK is, definitionally, a promise that the column CAN become null).
 * Fix: drop that `SET NULL` FK first, make the column `NOT NULL`, then
 * recreate the FK with `RESTRICT` instead — the correct rule for this
 * relationship anyway (an operational TeamMember must never be able to
 * silently lose its canonical TeamProfile out from under it; deleting a
 * TeamProfile that still has a TeamMember must be a blocked, explicit
 * decision, not a silent cascade or a silent null-out).
 *
 * Every schema-mutating step below is individually idempotent (checks
 * information_schema on MySQL, or just proceeds — Schema::hasColumn/etc.
 * on other drivers where this migration realistically only runs once, e.g.
 * the SQLite test database) so a retry after a partial failure — including
 * a failure partway between steps, since MySQL DDL auto-commits each
 * statement — is safe and never throws "already exists" / "doesn't exist".
 *
 * The old `unique(user_id, department_id)` composite is dropped because it
 * encoded the now-superseded "one membership per department" model; the new
 * `unique(team_profile_id)` fully replaces it under the "one membership per
 * canonical member" model, and every application write path already sets
 * team_profile_id (see AdminTeamMemberController::store()).
 *
 * team_members.department_id / role_title / joined_at are deliberately left
 * in place — Stage 5 (dropping them) is a separate, later task.
 */
return new class extends Migration
{
    private const TEAM_PROFILE_FK = 'team_members_team_profile_id_foreign';

    public function up(): void
    {
        $this->assertNoUnresolvedData();

        // STEP 1: give the user_id FK its own supporting index BEFORE
        // touching the composite unique it currently depends on.
        $this->ensureIndex('team_members', 'user_id', 'team_members_user_id_index');

        // STEP 2: now safe to drop — user_id's FK is covered by the index above.
        $this->dropUniqueIfExists('team_members', 'team_members_user_id_department_id_unique');

        // STEP 3: drop the team_profile_id FK ONLY IF it's still the old
        // SET NULL rule — a NOT NULL column cannot coexist with a SET NULL
        // FK. Leaves every OTHER team_members FK (department_id, section_id,
        // unit_id, user_id) completely untouched.
        if ($this->foreignKeyDeleteRule('team_members', self::TEAM_PROFILE_FK) === 'SET NULL') {
            Schema::table('team_members', function (Blueprint $table) {
                $table->dropForeign(self::TEAM_PROFILE_FK);
            });
        }

        // STEP 4: now safe — no FK depends on team_profile_id being nullable.
        if ($this->columnIsNullable('team_members', 'team_profile_id')) {
            Schema::table('team_members', function (Blueprint $table) {
                $table->unsignedBigInteger('team_profile_id')->nullable(false)->change();
            });
        }

        // STEP 5: UNIQUE(team_profile_id) — one operational TeamMember per
        // canonical TeamProfile.
        $this->ensureUnique('team_members', 'team_profile_id', 'team_members_team_profile_id_unique');

        // STEP 6: recreate the FK with RESTRICT — an operational TeamMember
        // must never lose its TeamProfile silently (no SET NULL, no CASCADE);
        // deleting a still-referenced TeamProfile must be an explicit,
        // blocked, controlled decision (see AdminTeamProfileController).
        if (! $this->foreignKeyExists('team_members', self::TEAM_PROFILE_FK)) {
            Schema::table('team_members', function (Blueprint $table) {
                $table->foreign('team_profile_id', self::TEAM_PROFILE_FK)
                    ->references('id')->on('team_profiles')
                    ->restrictOnDelete();
            });
        }

        // STEP 7: team_profiles.user_id also has a pre-existing FK to
        // users.id — give it its own standalone supporting index too,
        // for the exact same 1553-avoidance reason as STEP 1 (discovered by
        // testing this migration end-to-end against a disposable MySQL
        // database: adding a UNIQUE index over the same single column a
        // plain FK-supporting index already covers leaves the unique index
        // as the FK's ONLY support, which then blocks ever dropping it
        // again — e.g. on rollback).
        $this->ensureIndex('team_profiles', 'user_id', 'team_profiles_user_id_index');

        // STEP 8: team_profiles.user_id — UNIQUE (nullable-safe: multiple
        // NULLs remain allowed for unlinked members; only non-null values
        // must be unique). Safe now — the FK is covered by the index above,
        // not by this unique index alone.
        $this->ensureUnique('team_profiles', 'user_id', 'team_profiles_user_id_unique');
    }

    public function down(): void
    {
        // The standalone index from STEP 7 (if this migration created it)
        // must survive the unique-index drop below, so the pre-existing
        // team_profiles.user_id -> users.id FK always keeps a valid
        // supporting index — mirrors the team_members.user_id handling.
        $this->ensureIndex('team_profiles', 'user_id', 'team_profiles_user_id_index');
        $this->dropUniqueIfExists('team_profiles', 'team_profiles_user_id_unique');

        // Drop the RESTRICT FK before relaxing NOT NULL — same "column
        // nullability must never disagree with the FK's delete rule" rule
        // as up(), just approached from the other direction: RESTRICT
        // itself doesn't block a nullable column, but keep the same
        // drop-then-recreate discipline for a clean, well-defined FK swap.
        if ($this->foreignKeyExists('team_members', self::TEAM_PROFILE_FK)) {
            Schema::table('team_members', function (Blueprint $table) {
                $table->dropForeign(self::TEAM_PROFILE_FK);
            });
        }

        $this->dropUniqueIfExists('team_members', 'team_members_team_profile_id_unique');

        if (! $this->columnIsNullable('team_members', 'team_profile_id')) {
            Schema::table('team_members', function (Blueprint $table) {
                $table->unsignedBigInteger('team_profile_id')->nullable()->change();
            });
        }

        // Restore the original SET NULL FK.
        if (! $this->foreignKeyExists('team_members', self::TEAM_PROFILE_FK)) {
            Schema::table('team_members', function (Blueprint $table) {
                $table->foreign('team_profile_id', self::TEAM_PROFILE_FK)
                    ->references('id')->on('team_profiles')
                    ->nullOnDelete();
            });
        }

        // Restore the composite unique BEFORE dropping the standalone
        // user_id index, so user_id's FK always has a valid supporting
        // index throughout the rollback (the composite takes over first).
        $this->ensureUnique('team_members', ['user_id', 'department_id'], 'team_members_user_id_department_id_unique');
        $this->dropIndexIfExists('team_members', 'team_members_user_id_index');
    }

    /** Hard-abort with zero schema change if any of Stage 2's preconditions no longer hold. */
    private function assertNoUnresolvedData(): void
    {
        $unresolvedMembers = DB::table('team_members')->whereNull('team_profile_id')->count();
        if ($unresolvedMembers > 0) {
            throw new \RuntimeException(
                "Aborting: {$unresolvedMembers} team_members row(s) still have a null team_profile_id. ".
                'Run `php artisan team-members:backfill-profile-link --apply` and resolve any reported '.
                'conflicts/orphans first.'
            );
        }

        $duplicateProfileLinks = DB::table('team_members')
            ->whereNotNull('team_profile_id')
            ->select('team_profile_id')
            ->groupBy('team_profile_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        if ($duplicateProfileLinks > 0) {
            throw new \RuntimeException(
                "Aborting: {$duplicateProfileLinks} team_profile_id value(s) are linked from more than one ".
                'team_members row. Resolve manually before this migration can proceed.'
            );
        }

        $duplicateProfileUsers = DB::table('team_profiles')
            ->whereNotNull('user_id')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        if ($duplicateProfileUsers > 0) {
            throw new \RuntimeException(
                "Aborting: {$duplicateProfileUsers} user(s) still have more than one team_profiles row. ".
                'Run `php artisan team-profiles:audit-duplicates` and resolve manually first.'
            );
        }
    }

    private function isMySql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (! $this->isMySql()) {
            // Non-MySQL (e.g. the SQLite test database) — this migration only
            // ever runs once per fresh schema there, so idempotency checks
            // aren't needed; treat as "doesn't exist" so the step always runs.
            return false;
        }

        $result = DB::select(
            'SELECT COUNT(*) as cnt FROM information_schema.statistics '.
            'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $indexName]
        );

        return ($result[0]->cnt ?? 0) > 0;
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        if (! $this->isMySql()) {
            // Assume nullable (the pre-migration state) on non-MySQL drivers —
            // matches the fresh-schema-every-run reality of the test suite.
            return true;
        }

        $result = DB::select(
            'SELECT IS_NULLABLE FROM information_schema.columns '.
            'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return ($result[0]->IS_NULLABLE ?? 'YES') === 'YES';
    }

    /** Does this named foreign key currently exist? */
    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        if (! $this->isMySql()) {
            return false;
        }

        $result = DB::select(
            'SELECT COUNT(*) as cnt FROM information_schema.table_constraints '.
            "WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'",
            [$table, $constraintName]
        );

        return ($result[0]->cnt ?? 0) > 0;
    }

    /** The DELETE_RULE ('SET NULL', 'RESTRICT', 'CASCADE', ...) of a named foreign key, or null if it doesn't exist. */
    private function foreignKeyDeleteRule(string $table, string $constraintName): ?string
    {
        if (! $this->isMySql()) {
            return null;
        }

        $result = DB::select(
            'SELECT DELETE_RULE FROM information_schema.referential_constraints '.
            'WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
            [$table, $constraintName]
        );

        return $result[0]->DELETE_RULE ?? null;
    }

    /** Create a plain (non-unique) index if it doesn't already exist under this name. */
    private function ensureIndex(string $table, string|array $columns, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->index((array) $columns, $indexName);
        });
    }

    /** Create a UNIQUE index if it doesn't already exist under this name. */
    private function ensureUnique(string $table, string|array $columns, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->unique((array) $columns, $indexName);
        });
    }

    private function dropUniqueIfExists(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName) && $this->isMySql()) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropUnique($indexName);
            });
        } catch (\Throwable $e) {
            // Already gone (e.g. re-running after a partial failure) — fine.
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName) && $this->isMySql()) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            // Already gone — fine.
        }
    }
};
