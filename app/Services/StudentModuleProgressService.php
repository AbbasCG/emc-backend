<?php

namespace App\Services;

use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\User;
use App\Support\CourseContentStatus;
use Illuminate\Support\Collection;

class StudentModuleProgressService
{
    public function __construct(
        protected CourseAssignmentBridgeService $bridge
    ) {}

    /**
     * @return array{
     *   id: int,
     *   course_id: int,
     *   title: string,
     *   sort_order: int,
     *   lessons_count: int,
     *   completed_lessons_count: int,
     *   assignments_count: int,
     *   submitted_assignments_count: int,
     *   progress_percentage: int,
     *   is_completed: bool
     * }
     */
    public function computeForModule(CourseModule $module, User $user): array
    {
        $lessons = $module->relationLoaded('lessons')
            ? $module->lessons
            : $module->lessons()->where('status', CourseContentStatus::ACTIVE)->get();

        $requiredLessons = $lessons->filter(fn (Lesson $l) => $l->isVisibleToStudents());
        $lessonIds       = $requiredLessons->pluck('id');

        $completedLessonIds = $lessonIds->isEmpty()
            ? collect()
            : LessonCompletion::query()
                ->where('user_id', $user->id)
                ->whereIn('lesson_id', $lessonIds)
                ->pluck('lesson_id');

        $assignments = $module->relationLoaded('courseAssignments')
            ? $module->courseAssignments
            : $module->courseAssignments()
                ->where('is_visible', true)
                ->whereIn('status', ['published', 'active'])
                ->get();

        $requiredAssignments = $assignments->filter(fn ($a) => (bool) $a->is_required);

        $lmsAssignmentIds = $requiredAssignments
            ->map(fn ($ca) => $this->bridge->ensureLmsAssignment($ca)->id)
            ->values();

        $submittedAssignmentIds = $lmsAssignmentIds->isEmpty()
            ? collect()
            : AssignmentSubmission::query()
                ->where('user_id', $user->id)
                ->whereIn('assignment_id', $lmsAssignmentIds)
                ->whereNotNull('submitted_at')
                ->whereIn('status', ['submitted', 'late', 'graded', 'reviewed'])
                ->pluck('assignment_id')
                ->unique();

        $lessonsCount               = $requiredLessons->count();
        $completedLessonsCount      = $completedLessonIds->intersect($lessonIds)->count();
        $assignmentsCount           = $requiredAssignments->count();
        $submittedAssignmentsCount  = $submittedAssignmentIds->count();

        $totalItems     = $lessonsCount + $assignmentsCount;
        $completedItems = $completedLessonsCount + $submittedAssignmentsCount;

        $progressPercentage = $totalItems > 0
            ? (int) round(($completedItems / $totalItems) * 100)
            : 0;

        $isCompleted = ($lessonsCount === 0 || $completedLessonsCount >= $lessonsCount)
            && ($assignmentsCount === 0 || $submittedAssignmentsCount >= $assignmentsCount)
            && $totalItems > 0;

        return [
            'id'                           => $module->id,
            'course_id'                    => $module->course_id,
            'title'                        => $module->title,
            'sort_order'                   => (int) $module->sort_order,
            'lessons_count'                => $lessonsCount,
            'completed_lessons_count'      => $completedLessonsCount,
            'assignments_count'            => $assignmentsCount,
            'submitted_assignments_count'  => $submittedAssignmentsCount,
            'progress_percentage'          => min(100, $progressPercentage),
            'is_completed'                 => $isCompleted,
        ];
    }

    /**
     * @param  Collection<int, CourseModule>  $modules
     * @return array<int, array<string, mixed>>
     */
    public function computeForModules(Collection $modules, User $user): array
    {
        return $modules
            ->map(fn (CourseModule $module) => $this->computeForModule($module, $user))
            ->values()
            ->all();
    }

    /**
     * Course-level curriculum progress roll-up (lesson → module → course).
     *
     * Formula: completed active lessons across visible/active modules ÷
     * total active lessons across visible/active modules. Hidden/draft
     * modules (and their lessons) are excluded entirely from both the
     * numerator and denominator — not merely from the numerator — since an
     * unpublished module was never eligible content to begin with.
     *
     * This is computed on demand, not persisted: StudentProgress has no
     * curriculum-percentage column, and adding one plus a write path was
     * judged riskier than a cheap, always-fresh computed value exposed via
     * the lesson/module response Resources.
     *
     * @return array{modules_count:int, lessons_count:int, completed_lessons_count:int, progress_percentage:int, is_completed:bool}
     */
    public function computeForCourse(Course $course, User $user): array
    {
        $modules = $course->relationLoaded('modules')
            ? $course->modules
            : $course->modules()->with('lessons')->orderBy('sort_order')->get();

        $visibleModules = $modules->filter(fn (CourseModule $m) => $m->isVisibleToStudents());

        $perModule = $this->computeForModules($visibleModules, $user);

        $lessonsCount          = array_sum(array_column($perModule, 'lessons_count'));
        $completedLessonsCount = array_sum(array_column($perModule, 'completed_lessons_count'));

        $progressPercentage = $lessonsCount > 0
            ? (int) round(($completedLessonsCount / $lessonsCount) * 100)
            : 0;

        return [
            'modules_count'           => $visibleModules->count(),
            'lessons_count'           => $lessonsCount,
            'completed_lessons_count' => $completedLessonsCount,
            'progress_percentage'     => min(100, $progressPercentage),
            'is_completed'            => $lessonsCount > 0 && $completedLessonsCount >= $lessonsCount,
        ];
    }
}
