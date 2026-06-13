<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\LearningPathItem;
use Illuminate\Support\Collection;

/**
 * Resolves which courses / assignments / submissions an instructor may review.
 *
 * Includes:
 * - Courses where course.instructor_id = instructor
 * - Courses inside learning paths where the instructor is path instructor OR teaches the course
 */
class InstructorSubmissionScopeService
{
    /** @var array<int, Collection<int, int>> */
    private array $courseIdCache = [];

    public function accessibleCourseIds(?Instructor $profile): Collection
    {
        if (! $profile) {
            return collect();
        }

        $cacheKey = (int) $profile->id;
        if (isset($this->courseIdCache[$cacheKey])) {
            return $this->courseIdCache[$cacheKey];
        }

        $direct = Course::query()
            ->where('instructor_id', $profile->id)
            ->pluck('id');

        $pathPrimary = LearningPathItem::query()
            ->whereHas('learningPath', fn ($q) => $q->where('instructor_id', $profile->id))
            ->pluck('course_id');

        $pathCoInstructor = LearningPathItem::query()
            ->whereHas('course', fn ($q) => $q->where('instructor_id', $profile->id))
            ->pluck('course_id');

        $ids = $direct
            ->merge($pathPrimary)
            ->merge($pathCoInstructor)
            ->filter(fn ($id) => $id !== null && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $this->courseIdCache[$cacheKey] = $ids;

        return $ids;
    }

    public function accessibleAssignmentIds(?Instructor $profile, ?int $assignmentId = null): Collection
    {
        $courseIds = $this->accessibleCourseIds($profile);
        if ($courseIds->isEmpty()) {
            return collect();
        }

        $query = Assignment::query()
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                    ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            });

        if ($assignmentId !== null && $assignmentId > 0) {
            $query->where('id', $assignmentId);
        }

        return $query->pluck('id');
    }

    public function instructorOwnsAssignment(?Instructor $profile, ?Assignment $assignment): bool
    {
        if (! $profile || ! $assignment) {
            return false;
        }

        $courseId = $assignment->course_id ?? $assignment->workshop?->course_id;
        if (! $courseId) {
            return false;
        }

        return $this->accessibleCourseIds($profile)->contains((int) $courseId);
    }

    public function instructorOwnsSubmission(?Instructor $profile, AssignmentSubmission $submission): bool
    {
        $submission->loadMissing(['assignment.workshop']);

        return $this->instructorOwnsAssignment($profile, $submission->assignment);
    }

    /**
     * Primary learning path context for a course visible to this instructor.
     *
     * @return array{id:int,title:string,slug:string}|null
     */
    public function learningPathSummaryForCourse(?Instructor $profile, ?int $courseId): ?array
    {
        if (! $profile || ! $courseId || $courseId <= 0) {
            return null;
        }

        if (! $this->accessibleCourseIds($profile)->contains($courseId)) {
            return null;
        }

        $path = LearningPath::query()
            ->select(['learning_paths.id', 'learning_paths.title', 'learning_paths.slug'])
            ->whereHas('items', fn ($q) => $q->where('course_id', $courseId))
            ->where(function ($q) use ($profile, $courseId) {
                $q->where('instructor_id', $profile->id)
                    ->orWhereHas('items', fn ($iq) => $iq
                        ->where('course_id', $courseId)
                        ->whereHas('course', fn ($cq) => $cq->where('instructor_id', $profile->id)));
            })
            ->orderBy('learning_paths.id')
            ->first();

        if (! $path) {
            return null;
        }

        return [
            'id'    => $path->id,
            'title' => $path->title,
            'slug'  => $path->slug,
        ];
    }
}
