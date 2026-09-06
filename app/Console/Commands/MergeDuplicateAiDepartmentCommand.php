<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\DepartmentSection;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\DynamicForm;
use App\Models\FinancialRequest;
use App\Models\HrApplication;
use App\Models\HrRequest;
use App\Models\JobTitle;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeCategory;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\Ticket;
use App\Models\UserDepartment;
use App\Models\VolunteerHrProfile;
use App\Models\VolunteerProfile;
use App\Models\WeeklyDepartmentReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Merges the confirmed-in-production duplicate AI department row into the
 * canonical one and, only if every relationship proves reference-free,
 * deletes the duplicate.
 *
 * Both sides are hard-guarded by exact id+slug — verified directly against
 * production, not discovered by heuristic:
 *   SOURCE: Department #11 / slug=ai-digital-transformation (leader_id=NULL,
 *           deputy_leader_id=NULL, operational_member_id=NULL, status=active,
 *           created_at=2026-06-12 — a one-off duplicate created by the dead,
 *           never-registered `DepartmentSeeder::class`, run manually at some
 *           point against production).
 *   TARGET: Department #13 / slug=ai-transformation / leader_id=2078
 *           (canonical). This command never writes departments.leader_id —
 *           canonical leadership is set exclusively via
 *           DepartmentLeadershipService in the normal Team Profile flow and
 *           is never touched here, in either direction. The target's
 *           leader_id is also verified to already be 2078 before any write;
 *           if it isn't, the production assumption this command was built
 *           against no longer holds and it aborts rather than guess.
 *
 * If Department #11 no longer exists (e.g. already merged), that's an
 * idempotent no-op, not an error.
 *
 * Every table with a department_id column is classified, per the relationship
 * audit, as either:
 *   MOVE      — current organizational ownership (team_profiles, team_members,
 *               departmental_units, department_sections, tasks, meetings,
 *               knowledge_categories, knowledge_articles, dynamic_forms,
 *               volunteer_hr_profiles, volunteer_profiles, user_departments
 *               pivot, courses) — reassigned to the target on --apply.
 *               courses.department_id specifically was re-audited and moved
 *               here from an earlier PRESERVE classification: it is a LIVE,
 *               editable ownership field (AdminCourseController::update()
 *               validates and writes it on every course edit — the shared
 *               validateCourse() rules apply on update, not just store) and
 *               it drives the department's live courses_count shown on
 *               /dashboard/super-admin/crud/departments. Leaving a
 *               department's real courses on a merged-away duplicate would
 *               misattribute current ownership, unlike a genuine report
 *               snapshot.
 *   PRESERVE  — historical snapshot/authorship, set once at creation and
 *               never rewritten by this app (tickets, meeting_reports,
 *               weekly_department_reports, hr_requests, hr_applications,
 *               financial_requests, documents, document_folders,
 *               class_groups, job_titles, calendar_events) — NEVER moved,
 *               since rewriting their department_id would falsify history
 *               (meeting_reports/weekly_department_reports in particular
 *               are tied to a specific meeting_id/week_start and submitting
 *               author, with no update path that ever changes department_id
 *               after creation — verified by code, not assumed).
 *               Any nonzero PRESERVE count permanently blocks hard deletion;
 *               the duplicate is archived (status='archived', the existing
 *               column — no new archive mechanism invented) instead.
 *
 * team_profiles.is_leader is force-cleared (false) on every profile being
 * moved BEFORE the move, via a direct Eloquent write — deliberately NOT via
 * DepartmentLeadershipService::syncFromTeamProfile(), which would attempt to
 * write departments.leader_id and could, in an edge case, null out the
 * target's canonical leader_id. This command never calls that service.
 *
 * departmental_units.code is a globally unique column (confirmed in its
 * migration) — two units can never coexist with the same code regardless of
 * department, so moving the duplicate's units can never collide.
 *
 * Dry-run by default. Idempotent: after a full delete, re-running finds no
 * duplicate and no-ops; after an archive (blocked by real historical data),
 * re-running recomputes the same zero MOVE / nonzero PRESERVE state and
 * leaves it archived.
 */
class MergeDuplicateAiDepartmentCommand extends Command
{
    private const TARGET_ID = 13;
    private const TARGET_SLUG = 'ai-transformation';
    private const TARGET_LEADER_ID = 2078;
    private const SOURCE_ID = 11;
    private const SOURCE_SLUG = 'ai-digital-transformation';

    /** @var array<int, class-string<\Illuminate\Database\Eloquent\Model>> table label => model */
    private const MOVE_MODELS = [
        'team_profiles'        => TeamProfile::class,
        'team_members'         => TeamMember::class,
        'departmental_units'   => DepartmentalUnit::class,
        'department_sections'  => DepartmentSection::class,
        'tasks'                => Task::class,
        'meetings'             => Meeting::class,
        'knowledge_categories' => KnowledgeCategory::class,
        'knowledge_articles'   => KnowledgeArticle::class,
        'dynamic_forms'        => DynamicForm::class,
        'volunteer_hr_profiles'=> VolunteerHrProfile::class,
        'volunteer_profiles'   => VolunteerProfile::class,
        'user_departments'     => UserDepartment::class,
        // courses.department_id is LIVE current ownership, not a historical
        // snapshot: AdminCourseController::update() validates and writes it
        // on every course edit (validateCourse()'s shared rules apply on
        // both store AND update, with no update-time exclusion), and it
        // directly drives the department's live `courses_count` shown on
        // /dashboard/super-admin/crud/departments (DepartmentController::
        // index() -> withCount('courses as courses_count')). Leaving a
        // department's real courses attached to a duplicate/archived row
        // would misattribute current ownership, unlike a report snapshot.
        'courses'              => Course::class,
    ];

    private const PRESERVE_MODELS = [
        'tickets'                   => Ticket::class,
        'meeting_reports'           => MeetingReport::class,
        'weekly_department_reports' => WeeklyDepartmentReport::class,
        'hr_requests'               => HrRequest::class,
        'hr_applications'           => HrApplication::class,
        'financial_requests'       => FinancialRequest::class,
        'documents'                 => Document::class,
        'document_folders'         => DocumentFolder::class,
        'class_groups'              => ClassGroup::class,
        'job_titles'                => JobTitle::class,
        'calendar_events'           => CalendarEvent::class,
    ];

    protected $signature = 'departments:merge-duplicate-ai {--apply : Actually perform the merge (default is dry-run, no writes)}';

    protected $description = 'Merge the confirmed-duplicate Department #11 (ai-digital-transformation) into the canonical Department #13 (ai-transformation, leader_id=2078), moving live organizational data and preserving historical records; deletes #11 only if it ends up truly reference-free.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $target = Department::find(self::TARGET_ID);
        if (! $target || $target->slug !== self::TARGET_SLUG) {
            $this->error('Department #'.self::TARGET_ID." is missing or is not slug=".self::TARGET_SLUG.' — aborting, no changes made (canonical target guard failed).');

            return self::FAILURE;
        }

        if ((int) $target->leader_id !== self::TARGET_LEADER_ID) {
            $this->error(
                "Department #".self::TARGET_ID.' leader_id is '.($target->leader_id ?? 'NULL').', expected '.self::TARGET_LEADER_ID.
                ' — aborting, no changes made (the production state this command was verified against no longer holds).'
            );

            return self::FAILURE;
        }

        $duplicate = Department::find(self::SOURCE_ID);

        if (! $duplicate) {
            $this->info('Department #'.self::SOURCE_ID.' no longer exists — nothing to do (already merged).');

            return self::SUCCESS;
        }

        if ($duplicate->slug !== self::SOURCE_SLUG) {
            $this->error(
                'Department #'.self::SOURCE_ID.' is slug='.$duplicate->slug.', expected slug='.self::SOURCE_SLUG.
                ' — aborting, no changes made (safety guard against acting on the wrong record).'
            );

            return self::FAILURE;
        }

        $this->line('');
        $this->info("TARGET (KEEP):   Department #{$target->id} / {$target->slug} / leader_id={$target->leader_id}");
        $this->info("SOURCE (MERGE):  Department #{$duplicate->id} / {$duplicate->slug} / leader_id=".($duplicate->leader_id ?? 'NULL'));
        $this->line('');

        $childDepartments = Department::where('parent_id', $duplicate->id)->count();

        $moveCounts = [];
        foreach (self::MOVE_MODELS as $label => $model) {
            $moveCounts[$label] = $model::where('department_id', $duplicate->id)->count();
        }

        $preserveCounts = [];
        foreach (self::PRESERVE_MODELS as $label => $model) {
            $preserveCounts[$label] = $model::where('department_id', $duplicate->id)->count();
        }

        $matrix = [];
        foreach ($moveCounts as $label => $count) {
            $matrix[] = [$label, 'MOVE', $count, $count > 0 ? 'WILL_MOVE' : 'NONE'];
        }
        foreach ($preserveCounts as $label => $count) {
            $matrix[] = [$label, 'PRESERVE', $count, $count > 0 ? 'WILL_KEEP (historical, stays on source)' : 'NONE'];
        }
        $matrix[] = ['child departments (parent_id)', 'STRUCTURAL', $childDepartments, $childDepartments > 0 ? 'BLOCKS MERGE — manual review' : 'NONE'];

        $this->table(['table', 'category', 'count', 'action'], $matrix);
        $this->line('');

        $anyPreserve = array_sum($preserveCounts) > 0;
        $predictedOutcome = $anyPreserve ? 'WILL_ARCHIVE' : 'WILL_DELETE';
        $this->info("Predicted outcome for Department #{$duplicate->id}: {$predictedOutcome}".
            ($anyPreserve ? ' (historical data will remain attached to it)' : ' (will end up fully reference-free)'));
        $this->line('');

        if (! $apply) {
            $this->info('Dry run — no writes made. Pass --apply to perform this merge.');

            return self::SUCCESS;
        }

        if ($childDepartments > 0) {
            $this->error('Duplicate has child departments (parent_id references it) — aborting, no changes made. Manual review required.');

            return self::FAILURE;
        }

        // departmental_units.code is a globally unique column (not scoped per
        // department — confirmed in the migration), so two units can never
        // coexist with the same code regardless of department. There is
        // nothing to collision-check: moving the duplicate's units can never
        // violate that constraint.

        $result = DB::transaction(function () use ($duplicate, $target) {
            $demotedLeaders = [];

            // TeamProfile: never call DepartmentLeadershipService here — that
            // writes departments.leader_id, and this command must NEVER touch
            // it (target's leader_id=2078 stays canonical, untouched either
            // direction). Force is_leader=false directly before moving.
            $leaderProfiles = TeamProfile::where('department_id', $duplicate->id)->where('is_leader', true)->get();
            foreach ($leaderProfiles as $profile) {
                $demotedLeaders[] = "TeamProfile#{$profile->id} (user_id={$profile->user_id})";
                $profile->update(['is_leader' => false]);
            }
            TeamProfile::where('department_id', $duplicate->id)->update(['department_id' => $target->id]);

            // DepartmentalUnit: moved BEFORE team_members, so the unit-ownership
            // check below sees units that were already the duplicate's as now
            // correctly belonging to the target (a member's unit is not
            // spuriously nulled out just because it hasn't "arrived" yet).
            DepartmentalUnit::where('department_id', $duplicate->id)->update(['department_id' => $target->id]);

            // TeamMember: move, then null any unit_id that doesn't belong to the target.
            $members = TeamMember::where('department_id', $duplicate->id)->get();
            foreach ($members as $member) {
                $updates = ['department_id' => $target->id];
                if ($member->unit_id && ! DepartmentalUnit::where('id', $member->unit_id)->where('department_id', $target->id)->exists()) {
                    $updates['unit_id'] = null;
                }
                $member->update($updates);
            }

            // DepartmentSection: no uniqueness constraint — safe to move.
            DepartmentSection::where('department_id', $duplicate->id)->update(['department_id' => $target->id]);

            foreach (['tasks' => Task::class, 'meetings' => Meeting::class, 'knowledge_categories' => KnowledgeCategory::class,
                'knowledge_articles' => KnowledgeArticle::class, 'dynamic_forms' => DynamicForm::class,
                'volunteer_hr_profiles' => VolunteerHrProfile::class, 'volunteer_profiles' => VolunteerProfile::class,
                'courses' => Course::class] as $model) {
                $model::where('department_id', $duplicate->id)->update(['department_id' => $target->id]);
            }

            // user_departments pivot: unique(user_id, department_id) — dedupe
            // instead of moving into a collision.
            $pivotRows = UserDepartment::where('department_id', $duplicate->id)->get();
            foreach ($pivotRows as $row) {
                $alreadyHasTarget = UserDepartment::where('user_id', $row->user_id)->where('department_id', $target->id)->exists();
                if ($alreadyHasTarget) {
                    $row->delete();

                    continue;
                }
                $updates = ['department_id' => $target->id];
                if ($row->unit_id && ! DepartmentalUnit::where('id', $row->unit_id)->where('department_id', $target->id)->exists()) {
                    $updates['unit_id'] = null;
                }
                $row->update($updates);
            }

            $remainingPreserve = [];
            foreach (self::PRESERVE_MODELS as $label => $model) {
                $count = $model::where('department_id', $duplicate->id)->count();
                if ($count > 0) {
                    $remainingPreserve[$label] = $count;
                }
            }

            $remainingMove = [];
            foreach (self::MOVE_MODELS as $label => $model) {
                $count = $model::where('department_id', $duplicate->id)->count();
                if ($count > 0) {
                    $remainingMove[$label] = $count;
                }
            }

            if ($remainingPreserve === [] && $remainingMove === []) {
                $duplicate->delete();

                return ['outcome' => 'DELETED', 'demoted_leaders' => $demotedLeaders, 'preserve' => $remainingPreserve];
            }

            if ($duplicate->status !== 'archived') {
                $duplicate->update(['status' => 'archived']);
            }

            return ['outcome' => 'ARCHIVED', 'demoted_leaders' => $demotedLeaders, 'preserve' => $remainingPreserve];
        });

        $this->line('');
        if ($result['demoted_leaders'] !== []) {
            $this->info('Cleared is_leader on '.count($result['demoted_leaders']).' moved profile(s), never touching target leader_id: '.implode('; ', $result['demoted_leaders']));
        }

        if ($result['outcome'] === 'DELETED') {
            $this->info("Duplicate Department #{$duplicate->id} had zero remaining references after the move — deleted.");
        } else {
            $this->info("Duplicate Department #{$duplicate->id} archived (status=archived) — historical data still references it: ".implode(', ', array_keys($result['preserve'])));
        }

        $target->refresh();
        $this->info("Target Department #{$target->id} leader_id remains: ".($target->leader_id ?? 'NULL'));

        return self::SUCCESS;
    }
}
