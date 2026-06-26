<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meetingLink  = $this->canSeeMeetingLink($request) ? $this->meeting_link : null;
        $now          = now();
        $startAt      = $this->start_at;
        $endAt        = $this->end_at;
        $status       = $this->status ?? 'draft';

        $isEnded    = $status === 'completed' || ($endAt && $endAt->lt($now));
        $isCancelled = $status === 'cancelled';
        $isLive     = $status === 'live' || (
            $startAt && $endAt &&
            $startAt->lte($now) && $endAt->gte($now) &&
            ! $isEnded && ! $isCancelled
        );
        $isUpcoming  = ! $isEnded && ! $isCancelled && ! $isLive && $startAt && $startAt->gt($now);

        $statusLabelAr = match (true) {
            $isCancelled        => 'ملغاة',
            $isEnded            => 'منتهية',
            $isLive             => 'مباشرة الآن',
            $isUpcoming         => 'قادمة',
            $status === 'draft' => 'مسودة',
            $status === 'published' => 'منشورة',
            $startAt === null   => 'بدون موعد',
            default             => 'قادمة',
        };

        return [
            'id'            => $this->id,
            'source_type'   => 'course_session',
            'course_id'     => $this->course_id,
            'module_id'     => $this->module_id,
            'title'         => $this->title,
            'description'   => $this->description,
            // ISO datetime
            'start_at'      => $startAt?->toISOString(),
            'end_at'        => $endAt?->toISOString(),
            // Aliases
            'starts_at'     => $startAt?->toISOString(),
            'ends_at'       => $endAt?->toISOString(),
            'session_date'  => $startAt?->toDateString(),
            'start_time'    => $startAt?->format('H:i'),
            'end_time'      => $endAt?->format('H:i'),
            // Location
            'location_type' => $this->location_type,
            'location'      => $this->location,
            // Meeting (gated)
            'meeting_link'  => $meetingLink,
            'meeting_url'   => $meetingLink,
            // Computed status
            'status'           => $status,
            'status_label_ar'  => $statusLabelAr,
            'is_ended'         => $isEnded,
            'is_cancelled'     => $isCancelled,
            'is_live'          => $isLive,
            'is_upcoming'      => $isUpcoming,
            // Meta
            'sort_order'    => $this->sort_order,
            'is_visible'    => $this->is_visible,
            // Course / instructor
            'course_title'     => $this->whenLoaded('course', fn () => $this->course?->title),
            'instructor_id'    => $this->whenLoaded('course', fn () => $this->course?->instructor?->id),
            'instructor_name'  => $this->whenLoaded('course', function () {
                $instr = $this->course?->instructor;
                return $instr?->user?->name ?? $instr?->name ?? null;
            }),
            // Attendance
            'attendance_count' => $this->when(
                isset($this->attendances_count),
                fn () => (int) $this->attendances_count
            ),
            // Link open tracking
            'link_open_count'    => $this->when(
                isset($this->link_opens_count),
                fn () => (int) $this->link_opens_count
            ),
            'link_open_students' => $this->whenLoaded('linkOpens', function () {
                return $this->linkOpens->map(fn ($lo) => [
                    'id'        => $lo->user?->id,
                    'name'      => $lo->user?->name,
                    'email'     => $lo->user?->email,
                    'opened_at' => $lo->opened_at?->toISOString(),
                ])->values();
            }),
            // Sub-resources
            'materials'     => CourseMaterialResource::collection($this->whenLoaded('materials')),
            'assignments'   => CourseAssignmentResource::collection($this->whenLoaded('assignments')),
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
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
