<?php

namespace App\Services;

use App\Models\Department;
use App\Support\DepartmentNameNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Merges one duplicate department into a canonical one, without data loss.
 *
 * AUTHORITY RULE: departments.leader_id stays the single source of leadership
 * truth. This service never infers a leader from team_profiles.is_leader,
 * user_departments or a role. A leader difference between the two rows is a
 * CONFLICT that blocks the merge until a human picks a side explicitly
 * (`leaderResolution`). team_profiles.is_leader is only re-synced afterwards,
 * as the display flag it is.
 *
 * Every table with a FK to departments is classified:
 *   MOVE      live organizational ownership — reassigned to the canonical row.
 *   PRESERVE  historical snapshot / provenance, written once and never
 *             re-pointed by the application (tickets, reports, requests,
 *             documents, ...). Rewriting it would falsify history, so it stays
 *             on the duplicate, which is then archived instead of deleted.
 *             `moveHistorical` is the explicit opt-in to re-point these too.
 * The classification was verified against production for the first AI-department
 * merge (see MergeDuplicateAiDepartmentCommand) and extended with the page-access
 * tables. A FK table that is in NEITHER list makes the plan refuse to apply, so
 * a future migration can never be merged over silently.
 *
 * All writes use query-builder updates inside one transaction, so row
 * timestamps are preserved.
 */
final class DepartmentMergeService
{
    /** table => column */
    public const MOVE = [
        'team_profiles' => 'department_id',
        'team_members' => 'department_id',
        'departmental_units' => 'department_id',
        'department_sections' => 'department_id',
        'tasks' => 'department_id',
        'meetings' => 'department_id',
        'knowledge_categories' => 'department_id',
        'knowledge_articles' => 'department_id',
        'dynamic_forms' => 'department_id',
        'volunteer_hr_profiles' => 'department_id',
        'volunteer_profiles' => 'department_id',
        'user_departments' => 'department_id',
        'courses' => 'department_id',
        'department_page_defaults' => 'department_id',
    ];

    /** table => column */
    public const PRESERVE = [
        'tickets' => 'department_id',
        'meeting_reports' => 'department_id',
        'weekly_department_reports' => 'department_id',
        'hr_requests' => 'department_id',
        // Draft opportunities are generated from an hr_request and keep that request's department, like it does.
        'volunteer_opportunities' => 'department_id',
        'hr_applications' => 'department_id',
        'financial_requests' => 'department_id',
        'documents' => 'department_id',
        'document_folders' => 'department_id',
        'class_groups' => 'department_id',
        'job_titles' => 'department_id',
        'calendar_events' => 'department_id',
        // Financial ledger record (carries a department_name snapshot). Also
        // FK onDelete('set null'): hard-deleting a department would silently
        // erase the attribution, so any row here must force archive-not-delete.
        'manual_expenses' => 'department_id',
        'user_page_access_overrides' => 'source_department_id',
    ];

    private const LEADER_FIELDS = ['leader_id', 'deputy_leader_id', 'operational_member_id'];

    /** @return array{move: array<string,int>, preserve: array<string,int>, children: int, total: int} */
    public function linkedCounts(Department $department): array
    {
        $count = function (array $map) use ($department): array {
            $out = [];
            foreach ($map as $table => $column) {
                $out[$table] = Schema::hasTable($table)
                    ? DB::table($table)->where($column, $department->id)->count()
                    : 0;
            }

            return $out;
        };

        $move = $count(self::MOVE);
        $preserve = $count(self::PRESERVE);
        $children = Department::where('parent_id', $department->id)->count();

        return [
            'move' => $move,
            'preserve' => $preserve,
            'children' => $children,
            'total' => array_sum($move) + array_sum($preserve) + $children,
        ];
    }

    /**
     * Foreign keys to departments that are in neither list.
     *
     * @return list<string> "table.column"
     */
    public function unclassifiedReferences(): array
    {
        $known = array_merge(
            array_map(fn ($t, $c) => "$t.$c", array_keys(self::MOVE), self::MOVE),
            array_map(fn ($t, $c) => "$t.$c", array_keys(self::PRESERVE), self::PRESERVE),
            ['departments.parent_id'],
        );

        $found = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (($fk['foreign_table'] ?? null) !== 'departments') {
                    continue;
                }
                foreach ($fk['columns'] as $column) {
                    $found[] = "$table.$column";
                }
            }
        }

        return array_values(array_diff(array_unique($found), $known));
    }

    /**
     * Pick the department to keep. Order: active status, is_active, has a
     * leader, most linked records, oldest, lowest id.
     *
     * @param Collection<int, Department> $departments
     * @return array{department: Department, reasons: list<string>}
     */
    public function recommendCanonical(Collection $departments): array
    {
        $scored = $departments->map(fn (Department $d) => [
            'd' => $d,
            'linked' => $this->linkedCounts($d)['total'],
        ]);

        // Higher "quality" first; ties fall to oldest, then lowest id.
        $quality = fn (array $row): array => [
            (int) ($row['d']->status === 'active'),
            (int) $row['d']->is_active,
            (int) ($row['d']->leader_id !== null),
            $row['linked'],
        ];
        $age = fn (array $row): array => [$row['d']->created_at?->getTimestamp() ?? PHP_INT_MAX, $row['d']->id];

        $sorted = $scored
            ->sort(fn ($x, $y) => ($quality($y) <=> $quality($x)) ?: ($age($x) <=> $age($y)))
            ->values();

        $winner = $sorted->first();
        /** @var Department $w */
        $w = $winner['d'];

        $reasons = [];
        if ($w->status === 'active') {
            $reasons[] = 'active';
        }
        if ($w->leader_id !== null) {
            $reasons[] = "has leader_id={$w->leader_id}";
        }
        $reasons[] = "{$winner['linked']} linked record(s)";
        $reasons[] = 'created '.($w->created_at?->toDateString() ?? 'unknown');

        return ['department' => $w, 'reasons' => $reasons];
    }

    /**
     * Read-only plan. Never writes.
     *
     * @param array{leader?: ?string, moveHistorical?: bool} $options
     */
    public function plan(Department $canonical, Department $duplicate, array $options = []): array
    {
        $resolution = $options['leader'] ?? null;
        $moveHistorical = (bool) ($options['moveHistorical'] ?? false);

        $conflicts = [];
        $warnings = [];

        if ($canonical->id === $duplicate->id) {
            $conflicts[] = 'Canonical and duplicate are the same department.';
        }
        if ($canonical->status === 'archived') {
            $conflicts[] = "Canonical department #{$canonical->id} is archived; refusing to merge into it.";
        }
        if ($canonical->parent_id === $duplicate->id || $duplicate->parent_id === $canonical->id) {
            $conflicts[] = 'The two departments are parent and child of each other; manual review required.';
        }

        $counts = $this->linkedCounts($duplicate);
        if ($counts['children'] > 0) {
            $conflicts[] = "Duplicate has {$counts['children']} child department(s) (parent_id); manual review required.";
        }

        foreach ($this->unclassifiedReferences() as $ref) {
            $conflicts[] = "Unclassified foreign key to departments: {$ref}. Add it to MOVE or PRESERVE before merging.";
        }

        // ── Leadership ──────────────────────────────────────────────────────
        $leadership = [];
        $needsResolution = false;
        foreach (self::LEADER_FIELDS as $field) {
            $c = $canonical->{$field};
            $d = $duplicate->{$field};

            $state = match (true) {
                $d === null => 'none',
                $c === $d => 'same',
                $c === null => 'adopt',
                default => 'conflict',
            };
            if (in_array($state, ['adopt', 'conflict'], true)) {
                $needsResolution = true;
            }

            $leadership[$field] = ['canonical' => $c, 'duplicate' => $d, 'state' => $state];
        }

        if ($needsResolution && $resolution === null) {
            $conflicts[] = 'Leadership differs between the departments. Re-run with --leader=canonical (keep the canonical leadership) or --leader=duplicate (take the duplicate\'s). It is never guessed.';
        }

        $displaced = [];
        foreach ($leadership as $field => $row) {
            if ($resolution === 'canonical' && $row['state'] !== 'none' && $row['state'] !== 'same') {
                $displaced[$field] = $row['duplicate'];
            }
            if ($resolution === 'duplicate' && $row['state'] === 'conflict') {
                $displaced[$field] = $row['canonical'];
            }
        }

        // ── Overlap risks (reported, not blocking) ─────────────────────────
        $dupUserIds = DB::table('team_profiles')->where('department_id', $duplicate->id)->whereNotNull('user_id')->pluck('user_id');
        $both = DB::table('team_profiles')->where('department_id', $canonical->id)->whereIn('user_id', $dupUserIds)->pluck('user_id');
        if ($both->isNotEmpty()) {
            $warnings[] = 'Same person has a public team profile in BOTH departments (user_id '.$both->implode(', ').'); they would appear twice under the canonical department. Review with team-profiles:audit-duplicates.';
        }

        $canonUnitKeys = DB::table('departmental_units')->where('department_id', $canonical->id)->get(['name_ar', 'name_en'])
            ->map(fn ($u) => DepartmentNameNormalizer::key($u->name_ar ?: $u->name_en))->filter()->all();
        $unitOverlap = DB::table('departmental_units')->where('department_id', $duplicate->id)->get(['code', 'name_ar', 'name_en'])
            ->filter(fn ($u) => in_array(DepartmentNameNormalizer::key($u->name_ar ?: $u->name_en), $canonUnitKeys, true));
        if ($unitOverlap->isNotEmpty()) {
            $warnings[] = 'Units with the same name exist under both departments ('.$unitOverlap->pluck('code')->implode(', ').'). They are moved, not merged; review them separately.';
        }

        $redundantDefaults = DB::table('department_page_defaults as dup')
            ->join('department_page_defaults as can', function ($j) use ($canonical) {
                $j->on('can.page_key', '=', 'dup.page_key')->on('can.audience', '=', 'dup.audience')
                    ->where('can.department_id', '=', $canonical->id);
            })->where('dup.department_id', $duplicate->id)->count();
        if ($redundantDefaults > 0) {
            $warnings[] = "$redundantDefaults page-default row(s) already exist on the canonical department and will be dropped from the duplicate, not duplicated.";
        }

        $flagged = DB::table('team_profiles')->where('department_id', $duplicate->id)->where('is_leader', true)->count();
        if ($flagged > 0) {
            $warnings[] = "$flagged moved team profile(s) carry an is_leader display flag; it is re-derived from departments.leader_id, never copied.";
        }

        if ($moveHistorical) {
            $warnings[] = 'moveHistorical is ON: tickets, reports, requests and other historical records are re-pointed to the canonical department (this rewrites history).';
        }

        $alreadyMerged = $duplicate->status === 'archived'
            && array_sum($counts['move']) === 0
            && (! $moveHistorical || array_sum($counts['preserve']) === 0);

        $remainingAfter = $moveHistorical ? 0 : array_sum($counts['preserve']);
        $outcome = $alreadyMerged ? 'NOOP' : ($remainingAfter === 0 && $counts['children'] === 0 ? 'DELETE' : 'ARCHIVE');

        return [
            'canonical' => $canonical,
            'duplicate' => $duplicate,
            'counts' => $counts,
            'leadership' => $leadership,
            'resolution' => $resolution,
            'displaced' => $displaced,
            'move_historical' => $moveHistorical,
            'conflicts' => $conflicts,
            'warnings' => $warnings,
            'outcome' => $outcome,
            'noop' => $alreadyMerged,
        ];
    }

    /**
     * Apply a merge. Re-plans inside the transaction against locked rows, so a
     * stale dry-run can never be applied.
     *
     * @param array{leader?: ?string, moveHistorical?: bool} $options
     * @return array{applied: bool, plan: array, moved: array<string,int>, outcome: ?string, conflicts: list<string>}
     */
    public function apply(Department $canonical, Department $duplicate, array $options = []): array
    {
        return DB::transaction(function () use ($canonical, $duplicate, $options) {
            $canon = Department::query()->lockForUpdate()->find($canonical->id);
            $dup = Department::query()->lockForUpdate()->find($duplicate->id);

            if (! $canon || ! $dup) {
                $missing = ['A department no longer exists.'];

                return ['applied' => false, 'plan' => ['conflicts' => $missing, 'noop' => false], 'moved' => [], 'outcome' => null, 'conflicts' => $missing];
            }

            $plan = $this->plan($canon, $dup, $options);

            if ($plan['conflicts'] !== []) {
                return ['applied' => false, 'plan' => $plan, 'moved' => [], 'outcome' => null, 'conflicts' => $plan['conflicts']];
            }
            if ($plan['noop']) {
                return ['applied' => false, 'plan' => $plan, 'moved' => [], 'outcome' => 'NOOP', 'conflicts' => []];
            }

            $moved = [];
            $resolution = $plan['resolution'];

            // 1. Leadership — only on an explicit decision, never inferred.
            $leaderChanged = false;
            if ($resolution === 'duplicate') {
                $updates = [];
                foreach ($plan['leadership'] as $field => $row) {
                    if ($row['state'] === 'adopt' || $row['state'] === 'conflict') {
                        $updates[$field] = $row['duplicate'];
                        $leaderChanged = $leaderChanged || $field === 'leader_id';
                    }
                }
                if ($updates !== []) {
                    DB::table('departments')->where('id', $canon->id)->update($updates);
                    $canon->refresh();
                }
            }

            // 2. Team profiles: move, then re-derive the display flag.
            $profileIds = DB::table('team_profiles')->where('department_id', $dup->id)->pluck('id')->all();
            if ($profileIds !== []) {
                DB::table('team_profiles')->whereIn('id', $profileIds)->update(['department_id' => $canon->id, 'is_leader' => false]);
                if ($canon->leader_id) {
                    $alreadyFlagged = DB::table('team_profiles')->where('department_id', $canon->id)
                        ->where('user_id', $canon->leader_id)->where('is_leader', true)->exists();
                    if (! $alreadyFlagged) {
                        DB::table('team_profiles')->whereIn('id', $profileIds)->where('user_id', $canon->leader_id)->update(['is_leader' => true]);
                    }
                }
            }
            $moved['team_profiles'] = count($profileIds);

            // 3. Units before members, so a member's unit is already the canonical's.
            $moved['departmental_units'] = DB::table('departmental_units')->where('department_id', $dup->id)->update(['department_id' => $canon->id]);

            $memberIds = DB::table('team_members')->where('department_id', $dup->id)->pluck('id')->all();
            if ($memberIds !== []) {
                DB::table('team_members')->whereIn('id', $memberIds)->update(['department_id' => $canon->id]);
                DB::table('team_members')->whereIn('id', $memberIds)->whereNotNull('unit_id')
                    ->whereNotIn('unit_id', DB::table('departmental_units')->where('department_id', $canon->id)->select('id'))
                    ->update(['unit_id' => null]);
            }
            $moved['team_members'] = count($memberIds);

            // 4. user_departments: unique(user_id, department_id) — fold, never collide.
            $pivotMoved = 0;
            foreach (DB::table('user_departments')->where('department_id', $dup->id)->get() as $row) {
                $existing = DB::table('user_departments')->where('user_id', $row->user_id)->where('department_id', $canon->id)->first();
                if ($existing) {
                    if (($row->is_primary ?? false) && ! ($existing->is_primary ?? false)) {
                        DB::table('user_departments')->where('id', $existing->id)->update(['is_primary' => true]);
                    }
                    DB::table('user_departments')->where('id', $row->id)->delete();
                } else {
                    $update = ['department_id' => $canon->id];
                    if ($row->unit_id && ! DB::table('departmental_units')->where('id', $row->unit_id)->where('department_id', $canon->id)->exists()) {
                        $update['unit_id'] = null;
                    }
                    DB::table('user_departments')->where('id', $row->id)->update($update);
                }
                $pivotMoved++;
            }
            $moved['user_departments'] = $pivotMoved;

            // 5. Page defaults: unique(department_id, page_key, audience) — fold.
            $defaultsMoved = 0;
            foreach (DB::table('department_page_defaults')->where('department_id', $dup->id)->get() as $row) {
                $exists = DB::table('department_page_defaults')->where('department_id', $canon->id)
                    ->where('page_key', $row->page_key)->where('audience', $row->audience)->exists();
                if ($exists) {
                    DB::table('department_page_defaults')->where('id', $row->id)->delete();
                } else {
                    DB::table('department_page_defaults')->where('id', $row->id)->update(['department_id' => $canon->id]);
                }
                $defaultsMoved++;
            }
            $moved['department_page_defaults'] = $defaultsMoved;

            // 6. Everything else live: a plain re-point.
            $special = ['team_profiles', 'team_members', 'departmental_units', 'user_departments', 'department_page_defaults'];
            foreach (self::MOVE as $table => $column) {
                if (in_array($table, $special, true) || ! Schema::hasTable($table)) {
                    continue;
                }
                $moved[$table] = DB::table($table)->where($column, $dup->id)->update([$column => $canon->id]);
            }

            // 7. Historical records, only on explicit request.
            if ($plan['move_historical']) {
                foreach (self::PRESERVE as $table => $column) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }
                    $moved[$table] = DB::table($table)->where($column, $dup->id)->update([$column => $canon->id]);
                }
            }

            // 8. A leader taken from the duplicate keeps every profile flag consistent.
            if ($leaderChanged) {
                app(DepartmentLeadershipService::class)->syncFromDepartmentLeaderId($canon, $canon->leader_id);
            }

            // 9. Dispose of the duplicate: delete only if it is now reference-free.
            $remaining = $this->linkedCounts($dup);
            $leftovers = array_filter($remaining['move']) + array_filter($remaining['preserve']);
            if ($leftovers === [] && $remaining['children'] === 0) {
                DB::table('departments')->where('id', $dup->id)->delete();
                $outcome = 'DELETED';
            } else {
                DB::table('departments')->where('id', $dup->id)->update(['status' => 'archived', 'is_active' => false]);
                $outcome = 'ARCHIVED';
            }

            Log::info('departments.merge.applied', [
                'canonical_id' => $canon->id,
                'duplicate_id' => $dup->id,
                'outcome' => $outcome,
                'moved' => array_filter($moved),
                'leader_resolution' => $resolution,
                'displaced' => $plan['displaced'],
                'move_historical' => $plan['move_historical'],
            ]);

            return ['applied' => true, 'plan' => $plan, 'moved' => $moved, 'outcome' => $outcome, 'conflicts' => []];
        });
    }
}
