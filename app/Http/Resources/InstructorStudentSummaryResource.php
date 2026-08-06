<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ticket 2 canonical output for every instructor-facing student endpoint.
 * Wraps a row from PlacementProgressService::studentsForCourse()/
 * studentsForInstructor()/studentsForClassGroup()/studentSummary() —
 * never re-derives any value itself, only reshapes what the service
 * already computed.
 *
 * Backwards compatible by construction: every existing flat key
 * (student_id, written_score, class_assignment, ...) is preserved
 * unchanged at the top level, with the nested student/course/registration/
 * placement/class_assignment/progress structure added alongside it. No
 * existing frontend consumer breaks; new consumers may read either shape.
 */
class InstructorStudentSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        $row = $this->resource;

        // Backward-compatible identity aliases: some endpoints (e.g.
        // groupStudents()) key student name/email as `name`/`email` rather
        // than `student_name`/`student_email`. Never overwrite an existing
        // key — only fill in whichever alias is missing, so every existing
        // consumer of either naming keeps working unchanged.
        $studentName  = $row['student_name']  ?? $row['name']  ?? null;
        $studentEmail = $row['student_email'] ?? $row['email'] ?? null;
        if (! isset($row['student_name']))  { $row['student_name']  = $studentName; }
        if (! isset($row['student_email'])) { $row['student_email'] = $studentEmail; }
        if (! isset($row['name']))  { $row['name']  = $studentName; }
        if (! isset($row['email'])) { $row['email'] = $studentEmail; }

        return array_merge($row, [
            'student' => [
                'id'         => $row['student_id'] ?? null,
                'name'       => $studentName,
                'email'      => $studentEmail,
                'phone'      => $row['student_phone'] ?? null,
                'avatar_url' => $row['avatar_url'] ?? null,
            ],
            'course' => isset($row['course_id']) ? [
                'id'    => $row['course_id'],
                'title' => $row['course_title'] ?? null,
            ] : null,
            'registration' => [
                'status' => $row['registration_status'] ?? $row['enrollment_status'] ?? null,
            ],
            // Merged, not overwritten: some endpoints (groupStudents()) already
            // ship their own flat `placement` sub-object (written_score/
            // written_total/percentage/final_level) for an older frontend
            // consumer. Those keys are preserved alongside the new nested
            // written/oral/status structure rather than replaced.
            'placement' => array_merge($row['placement'] ?? [], [
                'written' => [
                    'score'     => $row['written_score'] ?? null,
                    'total'     => $row['total_questions'] ?? null,
                    'percentage'=> $row['percentage'] ?? null,
                    'level'     => $row['written_level'] ?? null,
                ],
                'oral' => [
                    'score'     => $row['oral_score'] ?? null,
                    'level'     => $row['final_level'] ?? null,
                    'breakdown' => $row['oral_assessment_full'] ?? $row['oral_assessment'] ?? null,
                ],
                'final_level' => $row['final_level'] ?? null,
                'status'      => $row['placement_status'] ?? null,
            ]),
            'class_assignment' => $row['class_assignment'] ?? null,
            'progress' => [
                'is_assigned'       => $row['is_assigned'] ?? false,
                'placement_status'  => $row['placement_status'] ?? null,
                'placement_progress'=> $row['placement_progress'] ?? null,
            ],
        ]);
    }
}
