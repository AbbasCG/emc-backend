<?php

namespace App\Http\Resources;

use App\Services\LmsSessionTransitionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LmsSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $date     = $this->session_date?->toDateString();
        $startsAt = $date && $this->start_time ? $date . 'T' . $this->start_time . ':00' : $date;
        $endsAt   = $date && $this->end_time   ? $date . 'T' . $this->end_time   . ':00' : null;
        $canSeeMeeting = $this->canSeeMeetingLink($request);
        $isInstructorViewer = $this->isInstructorViewer($request);
        $transitionService = app(LmsSessionTransitionService::class);
        $attendances = $this->relationLoaded('attendances') ? $this->attendances : collect();

        return [
            'id'            => $this->id,
            'source_type'   => 'lms_session',
            'workshop_id'   => $this->workshop_id,
            'course_id'     => $this->course_id,
            'instructor_id' => $this->instructor_id,
            'title'         => $this->title,
            'description'   => $this->description,
            // Raw date/time fields
            'session_date'  => $date,
            'start_time'    => $this->start_time,
            'end_time'      => $this->end_time,
            // ISO datetime aliases (consistent with course_sessions)
            'starts_at'     => $startsAt,
            'start_at'      => $startsAt,
            'ends_at'       => $endsAt,
            'end_at'        => $endsAt,
            'meeting_url'   => $canSeeMeeting ? $this->meeting_url : null,
            'meeting_link'  => $canSeeMeeting ? $this->meeting_url : null,
            'recording_url' => $this->recording_url,
            'status'        => $this->status,
            'location'      => $this->location,
            'workshop'      => WorkshopResource::make($this->whenLoaded('workshop')),
            'instructor_name' => $this->whenLoaded('instructor', fn () => $this->instructor?->name),
            'course_title'  => $this->whenLoaded('course', fn () => $this->course?->title),
            'course_name'   => $this->whenLoaded('course', fn () => $this->course?->title),
            'instructor'    => InstructorResource::make($this->whenLoaded('instructor')),
            'attendances'      => AttendanceResource::collection($this->whenLoaded('attendances')),
            'attendance_count' => $this->when(isset($this->attendances_count), fn () => (int) $this->attendances_count),
            'created_at'       => $this->created_at,

            // ── Canonical nested shape (Ticket 4, Block A) — additive, does
            //    not replace any field above. ─────────────────────────────
            'date'        => $date,
            'timezone'    => config('app.booking_timezone', 'Europe/Amsterdam'),
            'course'      => $this->relationLoaded('course') && $this->course
                ? ['id' => $this->course->id, 'title' => $this->course->title]
                : ($this->course_id ? ['id' => $this->course_id, 'title' => null] : null),
            'class_group' => $this->relationLoaded('classGroup') && $this->classGroup
                ? ['id' => $this->classGroup->id, 'name' => $this->classGroup->name]
                : ($this->class_group_id ? ['id' => $this->class_group_id, 'name' => null] : null),
            'meeting'     => [
                'provider'     => $this->meeting_provider ?? ($this->meeting_url ? 'custom' : 'none'),
                'url'          => $canSeeMeeting ? $this->meeting_url : null,
                'join_allowed' => $canSeeMeeting && $this->status === 'live' && ! empty($this->meeting_url),
            ],
            'attendance'  => [
                'total'   => $attendances->count(),
                'present' => $attendances->where('status', 'present')->count(),
                'absent'  => $attendances->where('status', 'absent')->count(),
                'late'    => $attendances->where('status', 'late')->count(),
                'excused' => $attendances->where('status', 'excused')->count(),
            ],
            'materials_count'   => $this->relationLoaded('materials') ? $this->materials->count() : 0,
            'assignments_count' => 0,
            'allowed_transitions' => $transitionService->allowedTransitions($this->resource),
            'permissions' => $isInstructorViewer ? [
                'view'              => true,
                'update'            => in_array($this->status, ['scheduled', 'live'], true),
                'transition'        => count($transitionService->allowedTransitions($this->resource)) > 0,
                'delete'            => $this->status === 'scheduled' && $attendances->count() === 0,
                'view_meeting_link' => true,
                'record_attendance' => in_array($this->status, ['live', 'completed'], true),
            ] : [
                'view'              => true,
                'update'            => false,
                'transition'        => false,
                'delete'            => false,
                'view_meeting_link' => $canSeeMeeting,
                'record_attendance' => false,
            ],
        ];
    }

    private function canSeeMeetingLink(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        if ($user->hasAnyRole(['admin', 'super_admin', 'tech_admin', 'executive_admin', 'instructor', 'teacher', 'programs_manager'])) {
            return true;
        }
        if ($user->isStudent()) {
            // Class-scoped session: meeting-link visibility requires active
            // membership in THAT class group, not just course-wide
            // registration — otherwise a student in another class within the
            // same course could see a meeting link that isn't theirs.
            if ($this->class_group_id !== null) {
                return \App\Models\ClassGroupStudent::where('class_group_id', $this->class_group_id)
                    ->where('user_id', $user->id)
                    ->whereIn('status', \App\Models\ClassGroupStudent::ACTIVE_STATUSES)
                    ->exists();
            }

            return $user->registrations()
                ->where('course_id', $this->course_id)
                ->whereIn('status', ['active', 'registered', 'enrolled', 'completed'])
                ->exists();
        }
        return false;
    }

    private function isInstructorViewer(Request $request): bool
    {
        $user = $request->user();
        return (bool) ($user && $user->hasAnyRole(['admin', 'super_admin', 'tech_admin', 'executive_admin', 'instructor']));
    }
}
