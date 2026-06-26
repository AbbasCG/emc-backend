<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LmsSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $date     = $this->session_date?->toDateString();
        $startsAt = $date && $this->start_time ? $date . 'T' . $this->start_time . ':00' : $date;
        $endsAt   = $date && $this->end_time   ? $date . 'T' . $this->end_time   . ':00' : null;

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
            'meeting_url'   => $this->canSeeMeetingLink($request) ? $this->meeting_url : null,
            'meeting_link'  => $this->canSeeMeetingLink($request) ? $this->meeting_url : null,
            'recording_url' => $this->recording_url,
            'status'        => $this->status,
            'location'      => $this->location,
            'workshop'      => WorkshopResource::make($this->whenLoaded('workshop')),
            'instructor_name' => $this->whenLoaded('instructor', fn () => $this->instructor?->name),
            'course_title'  => $this->whenLoaded('course', fn () => $this->course?->title),
            'instructor'    => InstructorResource::make($this->whenLoaded('instructor')),
            'attendances'      => AttendanceResource::collection($this->whenLoaded('attendances')),
            'attendance_count' => $this->when(isset($this->attendances_count), fn () => (int) $this->attendances_count),
            'created_at'       => $this->created_at,
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
            return $user->registrations()
                ->where('course_id', $this->course_id)
                ->whereIn('status', ['active', 'registered', 'enrolled', 'completed'])
                ->exists();
        }
        return false;
    }
}
