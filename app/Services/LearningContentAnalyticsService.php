<?php

namespace App\Services;

use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\MaterialAuditLog;
use App\Support\CourseContentStatus;
use Illuminate\Support\Collection;

/**
 * Class-scoped learning-content analytics — real, persisted data only.
 * Bulk-queried throughout: one query per aggregate, never per student or
 * per lesson (see the query-count test in LearningContentAnalyticsTest).
 */
class LearningContentAnalyticsService
{
    public function forClass(ClassGroup $group): array
    {
        $courseId = $group->course_id;

        // ── Eligible students: active members of THIS class only ───────────
        $studentIds = ClassGroupStudent::where('class_group_id', $group->id)
            ->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
            ->pluck('user_id');

        // ── Eligible modules/lessons: visible only, course-scoped ──────────
        $modules = CourseModule::where('course_id', $courseId)->with('lessons')->get()
            ->filter(fn (CourseModule $m) => $m->isVisibleToStudents());

        $eligibleLessons = $modules->flatMap(fn (CourseModule $m) => $m->lessons)
            ->filter(fn (Lesson $l) => $l->isVisibleToStudents());
        $lessonIds = $eligibleLessons->pluck('id');

        // ── One aggregate query: completions grouped by lesson+user ────────
        $completions = $lessonIds->isEmpty() || $studentIds->isEmpty()
            ? collect()
            : LessonCompletion::whereIn('lesson_id', $lessonIds)
                ->whereIn('user_id', $studentIds)
                ->get(['lesson_id', 'user_id']);

        $completedLessonIdsByStudent = $completions->groupBy('user_id')
            ->map(fn (Collection $rows) => $rows->pluck('lesson_id')->unique());

        $totalEligibleLessons = $lessonIds->count();

        // ── Per-student state ───────────────────────────────────────────────
        $notStarted = 0;
        $inProgress = 0;
        $completed = 0;
        $progressSum = 0;

        foreach ($studentIds as $studentId) {
            $doneCount = ($completedLessonIdsByStudent[$studentId] ?? collect())->count();
            $pct = $totalEligibleLessons > 0 ? ($doneCount / $totalEligibleLessons) * 100 : 0;
            $progressSum += $pct;

            if ($totalEligibleLessons === 0 || $doneCount === 0) {
                $notStarted++;
            } elseif ($doneCount >= $totalEligibleLessons) {
                $completed++;
            } else {
                $inProgress++;
            }
        }

        $eligibleStudentsCount = $studentIds->count();
        $avgProgress = $eligibleStudentsCount > 0 ? round($progressSum / $eligibleStudentsCount, 2) : 0.0;

        // ── Module rows ──────────────────────────────────────────────────────
        $moduleRows = $modules->values()->map(function (CourseModule $module) use ($studentIds, $completedLessonIdsByStudent) {
            $moduleLessons = $module->lessons->filter(fn (Lesson $l) => $l->isVisibleToStudents());
            $moduleLessonIds = $moduleLessons->pluck('id');
            $possible = $moduleLessonIds->count() * max($studentIds->count(), 0);
            $doneTotal = 0;
            foreach ($studentIds as $studentId) {
                $doneTotal += ($completedLessonIdsByStudent[$studentId] ?? collect())->intersect($moduleLessonIds)->count();
            }
            return [
                'id' => $module->id,
                'title' => $module->title,
                'eligible_lessons' => $moduleLessonIds->count(),
                'completed_student_lessons' => $doneTotal,
                'possible_student_lessons' => $possible,
                'completion_percentage' => $possible > 0 ? (int) round(($doneTotal / $possible) * 100) : 0,
            ];
        })->values()->all();

        // ── Lesson rows ──────────────────────────────────────────────────────
        $completedCountByLesson = $completions->groupBy('lesson_id')->map(fn ($rows) => $rows->count());
        $lessonRows = $eligibleLessons->values()->map(function (Lesson $lesson) use ($studentIds, $completedCountByLesson) {
            $done = (int) ($completedCountByLesson[$lesson->id] ?? 0);
            $eligible = $studentIds->count();
            return [
                'id' => $lesson->id,
                'module_id' => $lesson->module_id,
                'title' => $lesson->title,
                'eligible_students' => $eligible,
                'completed_students' => $done,
                'completion_percentage' => $eligible > 0 ? (int) round(($done / $eligible) * 100) : 0,
            ];
        })->values()->all();

        // ── Materials: course-global (in this class's students) + class-scoped ──
        $materials = CourseMaterial::where('course_id', $courseId)
            ->where(fn ($q) => $q->whereNull('class_group_id')->orWhere('class_group_id', $group->id))
            ->get();
        $materialIds = $materials->pluck('id');

        $logs = $materialIds->isEmpty()
            ? collect()
            : MaterialAuditLog::whereIn('material_id', $materialIds)
                ->whereIn('user_id', $studentIds) // only this class's students count as engagement
                ->whereIn('action', ['downloaded', 'previewed', 'streamed'])
                ->get(['material_id', 'action']);

        $logsByMaterial = $logs->groupBy('material_id');

        $materialRows = $materials->values()->map(function (CourseMaterial $m) use ($logsByMaterial) {
            $rows = $logsByMaterial[$m->id] ?? collect();
            $downloads = $rows->where('action', 'downloaded')->count();
            $previews = $rows->where('action', 'previewed')->count();
            $streams = $rows->where('action', 'streamed')->count();
            return [
                'id' => $m->id,
                'title' => $m->title,
                'scope' => $m->class_group_id ? 'class' : ($m->lesson_id ? 'lesson' : ($m->module_id ? 'module' : 'course')),
                'downloads_count' => $downloads,
                'previews_count' => $previews,
                'streams_count' => $streams,
                'total_interactions' => $downloads + $previews + $streams,
            ];
        })->values()->all();

        return [
            'summary' => [
                'eligible_students' => $eligibleStudentsCount,
                'not_started_students' => $notStarted,
                'in_progress_students' => $inProgress,
                'completed_students' => $completed,
                'average_course_progress_percentage' => $avgProgress,
                'modules_count' => $modules->count(),
                'lessons_count' => $totalEligibleLessons,
                'materials_count' => $materials->count(),
                'downloads_count' => $logs->where('action', 'downloaded')->count(),
                'previews_count' => $logs->where('action', 'previewed')->count(),
                'streams_count' => $logs->where('action', 'streamed')->count(),
            ],
            'modules' => $moduleRows,
            'lessons' => $lessonRows,
            'materials' => $materialRows,
        ];
    }
}
