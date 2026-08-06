<?php

namespace App\Console\Commands;

use App\Models\ClassGroup;
use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Support\CourseContentStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * php artisan learning-content:audit [--repair]
 *
 * Dry-run by default. Detects structural inconsistencies across the
 * Course → CourseModule → Lesson → CourseMaterial hierarchy. Only
 * deterministic, non-destructive repairs are ever applied — never deletes
 * content, never guesses a course/class/lesson, never activates content.
 */
class LearningContentAuditCommand extends Command
{
    protected $signature = 'learning-content:audit {--repair : Apply deterministic repairs instead of only reporting}';

    protected $description = 'Audit the CourseModule/Lesson/CourseMaterial hierarchy for structural inconsistencies';

    /** @var array<int, array{issue:string, model:string, id:int, detail:string, repairable:bool}> */
    private array $findings = [];

    public function handle(): int
    {
        // Defensive reset: Artisan::call() can reuse a resolved command
        // instance within the same PHP process (e.g. two calls in one test),
        // and $findings is an instance property — never assume it starts empty.
        $this->findings = [];

        $repair = (bool) $this->option('repair');

        if ($repair) {
            $this->warn('Running in REPAIR mode — deterministic fixes will be applied.');
        } else {
            $this->info('Running in DRY-RUN mode (default) — no changes will be made.');
        }
        $this->newLine();

        $repairedCount = 0;

        $repairedCount += $this->checkOrphanLessons();
        $repairedCount += $this->checkOrphanMaterials($repair);
        $repairedCount += $this->checkDuplicateModuleSortOrders($repair);
        $repairedCount += $this->checkDuplicateLessonSortOrders($repair);
        $this->checkActiveLessonUnderUnavailableModule();
        $repairedCount += $this->checkMaterialMissingModuleFromLesson($repair);
        $this->checkClassScopedMaterialWithInvalidClass();
        $this->checkMissingPhysicalFiles();

        $this->newLine();
        $this->renderFindingsTable();

        $unrepairable = collect($this->findings)->reject(fn ($f) => $f['repairable'] && $repair)->count();

        $this->newLine();
        $this->info("Total findings: " . count($this->findings) . ($repair ? ", repaired: {$repairedCount}" : ''));

        return $unrepairable > 0 ? 1 : 0;
    }

    // ── Checks ───────────────────────────────────────────────────────────────

    private function checkOrphanLessons(): int
    {
        Lesson::query()
            ->whereDoesntHave('module')
            ->orWhereHas('module', fn ($q) => $q->onlyTrashed())
            ->get()
            ->each(function (Lesson $lesson) {
                $this->findings[] = [
                    'issue' => 'lesson_missing_or_deleted_module', 'model' => 'Lesson', 'id' => $lesson->id,
                    'detail' => "module_id={$lesson->module_id} missing or soft-deleted", 'repairable' => false,
                ];
            });

        return 0;
    }

    private function checkOrphanMaterials(bool $repair): int
    {
        $repaired = 0;

        CourseMaterial::query()->whereNotNull('module_id')->whereDoesntHave('module')->get()
            ->each(function (CourseMaterial $m) use ($repair, &$repaired) {
                $this->findings[] = [
                    'issue' => 'material_missing_module', 'model' => 'CourseMaterial', 'id' => $m->id,
                    'detail' => "module_id={$m->module_id} does not exist", 'repairable' => true,
                ];
                if ($repair) {
                    $m->update(['module_id' => null]);
                    $repaired++;
                }
            });

        CourseMaterial::query()->whereNotNull('lesson_id')->whereDoesntHave('lesson')->get()
            ->each(function (CourseMaterial $m) use ($repair, &$repaired) {
                $this->findings[] = [
                    'issue' => 'material_missing_lesson', 'model' => 'CourseMaterial', 'id' => $m->id,
                    'detail' => "lesson_id={$m->lesson_id} does not exist", 'repairable' => true,
                ];
                if ($repair) {
                    $m->update(['lesson_id' => null]);
                    $repaired++;
                }
            });

        return $repaired;
    }

    private function checkDuplicateModuleSortOrders(bool $repair): int
    {
        $repaired = 0;

        $duplicateCourseIds = CourseModule::query()
            ->select('course_id')
            ->groupBy('course_id', 'sort_order')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('course_id')
            ->unique();

        foreach ($duplicateCourseIds as $courseId) {
            $modules = CourseModule::where('course_id', $courseId)->orderBy('sort_order')->orderBy('id')->get();
            $this->findings[] = [
                'issue' => 'duplicate_module_sort_order', 'model' => 'Course', 'id' => (int) $courseId,
                'detail' => "modules [" . $modules->pluck('id')->implode(',') . "] have colliding sort_order values",
                'repairable' => true,
            ];
            if ($repair) {
                DB::transaction(function () use ($modules) {
                    foreach ($modules->values() as $position => $module) {
                        $module->update(['sort_order' => $position]);
                    }
                });
                $repaired++;
            }
        }

        return $repaired;
    }

    private function checkDuplicateLessonSortOrders(bool $repair): int
    {
        $repaired = 0;

        $duplicateModuleIds = Lesson::query()
            ->select('module_id')
            ->groupBy('module_id', 'sort_order')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('module_id')
            ->unique();

        foreach ($duplicateModuleIds as $moduleId) {
            $lessons = Lesson::where('module_id', $moduleId)->orderBy('sort_order')->orderBy('id')->get();
            $this->findings[] = [
                'issue' => 'duplicate_lesson_sort_order', 'model' => 'CourseModule', 'id' => (int) $moduleId,
                'detail' => "lessons [" . $lessons->pluck('id')->implode(',') . "] have colliding sort_order values",
                'repairable' => true,
            ];
            if ($repair) {
                DB::transaction(function () use ($lessons) {
                    foreach ($lessons->values() as $position => $lesson) {
                        $lesson->update(['sort_order' => $position]);
                    }
                });
                $repaired++;
            }
        }

        return $repaired;
    }

    private function checkActiveLessonUnderUnavailableModule(): void
    {
        Lesson::query()
            ->where('status', CourseContentStatus::ACTIVE)
            ->whereHas('module', fn ($q) => $q->whereIn('status', CourseContentStatus::HIDDEN_FROM_STUDENTS))
            ->with('module')
            ->get()
            ->each(function (Lesson $lesson) {
                $this->findings[] = [
                    'issue' => 'active_lesson_under_unavailable_module', 'model' => 'Lesson', 'id' => $lesson->id,
                    'detail' => "lesson is active but module #{$lesson->module_id} status={$lesson->module?->status}",
                    'repairable' => false,
                ];
            });
    }

    private function checkMaterialMissingModuleFromLesson(bool $repair): int
    {
        $repaired = 0;

        CourseMaterial::query()
            ->whereNotNull('lesson_id')->whereNull('module_id')
            ->with('lesson')
            ->get()
            ->each(function (CourseMaterial $m) use ($repair, &$repaired) {
                if (! $m->lesson || ! $m->lesson->module_id) {
                    return;
                }
                $this->findings[] = [
                    'issue' => 'material_missing_derivable_module', 'model' => 'CourseMaterial', 'id' => $m->id,
                    'detail' => "lesson_id={$m->lesson_id} implies module_id={$m->lesson->module_id}, currently null",
                    'repairable' => true,
                ];
                if ($repair) {
                    $m->update(['module_id' => $m->lesson->module_id]);
                    $repaired++;
                }
            });

        return $repaired;
    }

    private function checkClassScopedMaterialWithInvalidClass(): void
    {
        CourseMaterial::query()
            ->whereNotNull('class_group_id')
            ->whereDoesntHave('classGroup')
            ->get()
            ->each(function (CourseMaterial $m) {
                $this->findings[] = [
                    'issue' => 'material_invalid_class_group', 'model' => 'CourseMaterial', 'id' => $m->id,
                    'detail' => "class_group_id={$m->class_group_id} does not exist", 'repairable' => false,
                ];
            });
    }

    private function checkMissingPhysicalFiles(): void
    {
        CourseMaterial::query()
            ->whereNotNull('file_path')
            ->chunkById(200, function ($materials) {
                foreach ($materials as $m) {
                    $disk = Storage::disk($m->disk ?? 'private');
                    if (! $disk->exists($m->file_path)) {
                        $this->findings[] = [
                            'issue' => 'missing_physical_file', 'model' => 'CourseMaterial', 'id' => $m->id,
                            'detail' => "file_path does not exist on disk '{$m->disk}'", 'repairable' => false,
                        ];
                    }
                }
            });
    }

    private function renderFindingsTable(): void
    {
        if ($this->findings === []) {
            $this->info('✓ No inconsistencies found.');
            return;
        }

        $this->table(
            ['Issue', 'Model', 'ID', 'Detail', 'Repairable'],
            collect($this->findings)->map(fn ($f) => [
                $f['issue'], $f['model'], $f['id'], $f['detail'], $f['repairable'] ? 'yes' : 'no',
            ])->all()
        );
    }
}
