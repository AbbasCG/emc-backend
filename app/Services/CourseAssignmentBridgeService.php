<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\CourseAssignment;

class CourseAssignmentBridgeService
{
    /**
     * Ensure an LMS Assignment row exists for a course-content assignment.
     */
    public function ensureLmsAssignment(CourseAssignment $courseAssignment): Assignment
    {
        $existing = Assignment::query()
            ->where('course_assignment_id', $courseAssignment->id)
            ->first();

        if ($existing) {
            $existing->update($this->mapCourseAssignmentAttributes($courseAssignment));

            return $existing->fresh();
        }

        return Assignment::query()->create(array_merge(
            $this->mapCourseAssignmentAttributes($courseAssignment),
            ['course_assignment_id' => $courseAssignment->id],
        ));
    }

    /**
     * Resolve by LMS assignment id or course_assignment id.
     */
    public function resolveAssignment(int $id): ?Assignment
    {
        $assignment = Assignment::query()->find($id);
        if ($assignment) {
            return $assignment;
        }

        $courseAssignment = CourseAssignment::query()->find($id);
        if (! $courseAssignment) {
            return null;
        }

        return $this->ensureLmsAssignment($courseAssignment);
    }

    /**
     * Sync all visible published course assignments for enrolled courses.
     *
     * @param  iterable<int>  $courseIds
     */
    public function syncForCourses(iterable $courseIds): void
    {
        $ids = collect($courseIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        CourseAssignment::query()
            ->whereIn('course_id', $ids)
            ->where('is_visible', true)
            ->whereIn('status', ['published', 'active'])
            ->each(fn (CourseAssignment $ca) => $this->ensureLmsAssignment($ca));
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapCourseAssignmentAttributes(CourseAssignment $courseAssignment): array
    {
        return [
            'course_id'            => $courseAssignment->course_id,
            // Ticket 5: propagate class/session scoping onto the bridge row
            // too — previously only course_id was mapped, so a class- or
            // session-scoped CourseAssignment silently produced a
            // course-wide bridge Assignment (visible to the whole course).
            'class_group_id'       => $courseAssignment->class_group_id,
            'session_id'           => $courseAssignment->session_id,
            'title'                => $courseAssignment->title,
            'description'          => $courseAssignment->description,
            'due_date'             => $courseAssignment->deadline,
            'max_score'            => $courseAssignment->max_points,
            'max_attempts'         => $courseAssignment->max_attempts,
            'status'               => in_array($courseAssignment->status, ['published', 'active'], true) ? 'active' : 'archived',
            'is_visible'           => (bool) $courseAssignment->is_visible,
            'resubmission_allowed' => (bool) $courseAssignment->resubmission_allowed,
        ];
    }
}
