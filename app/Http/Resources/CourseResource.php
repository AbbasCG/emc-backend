<?php

namespace App\Http\Resources;

use App\Support\CourseComputedStatus;
use App\Support\CourseDisplay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'track_id'              => $this->track_id,
            // Bilingual title aliases — DB stores a single Arabic title; en is null until extended
            'title'                 => $this->title,
            'title_ar'              => $this->title,
            'title_en'              => null,
            'slug'                  => $this->slug,
            'description'           => $this->description,
            'short_description'     => $this->short_description,
            'type'                  => $this->type,
            'price'                 => (float) $this->price,
            'is_free'               => (bool) $this->is_free,
            // is_paid: true when new column is set OR legacy type='paid'
            'is_paid'               => (bool) ($this->is_paid ?? false) || $this->type === 'paid',
            'currency'              => strtoupper($this->currency ?? 'EUR'),
            'status'                => $this->status,
            'program_type'          => $this->program_type,
            'content_type'          => $this->program_type,
            // Explicit boolean flag — frontend can use this instead of comparing program_type string
            'is_workshop'           => $this->program_type === 'workshop',
            'session_format'        => $this->session_format,
            'requires_placement_test' => (bool) $this->requires_placement_test,

            // Dates — always formatted as Y-m-d strings (cast returns Carbon, must format here)
            'start_date'            => $this->start_date?->format('Y-m-d'),
            'end_date'              => $this->end_date?->format('Y-m-d'),
            'start_time'            => $this->start_time,
            'end_time'              => $this->end_time,
            // Derived schedule string — study_time if set, else start_time–end_time, else null
            'schedule_time'         => $this->resolveScheduleTime(),
            'next_cohort_pending'   => ! $this->start_date,

            // Location / delivery
            'delivery_type'         => $this->location_type ?? 'unknown',
            'location_type'         => $this->location_type ?? 'unknown',
            'location'              => $this->location,
            'meeting_link'          => $this->canSeeMeetingLink($request) ? $this->meeting_link : null,
            'is_online'             => (bool) $this->is_online,
            'whatsapp_community_url' => $this->whatsapp_community_url,

            // Capacity
            'capacity'              => $this->capacity,
            'seats_count'           => $this->capacity,
            // Every registration row, whatever its status (admin screens read this one).
            'registrations_count'        => $this->registrations_count ?? 0,
            // The seat indicators below are the CANONICAL ones: the same definition registration enforces (an enrollment that grants
            // access or a payment in progress hold a seat; cancelled rows hold none). `seats_taken` falls back to the raw count only
            // where the query did not load it.
            'seats_taken'                => $this->seats_taken_count ?? ($this->registrations_count ?? 0),
            'seats_remaining'            => $this->capacity !== null && (int) $this->capacity > 0 ? max(0, (int) $this->capacity - (int) ($this->seats_taken_count ?? ($this->registrations_count ?? 0))) : null,
            'effective_enrollment_count' => $this->seats_taken_count ?? ($this->registrations_count ?? 0),
            // true when capacity is set and all seats are taken
            'is_full'               => $this->capacity !== null && (int) $this->capacity > 0 && (int) ($this->seats_taken_count ?? ($this->registrations_count ?? 0)) >= (int) $this->capacity,
            'registration_open'     => ! CourseComputedStatus::blocksPublicEnrollment($this->resource) && (bool) ($this->registration_open ?? true),
            'registration_status'   => CourseComputedStatus::blocksPublicEnrollment($this->resource) ? 'closed' : ($this->registration_status ?? 'open'),
            'requires_registration_code' => (bool) ($this->requires_registration_code ?? false),

            // Image — raw path, resolved full URL, and thumbnail
            'course_image'          => $this->course_image,
            'image_url'             => $this->resolveImageUrl($this->course_image),
            'thumbnail_url'         => $this->resolveImageUrl($this->course_thumbnail),

            // Learning content — always arrays, never null or JSON strings
            'learning_outcomes'     => $this->ensureArray($this->learning_outcomes),
            'requirements'          => $this->ensureArray($this->requirements),
            'curriculum_topics'     => $this->ensureArray($this->curriculum_topics),
            'keywords'              => $this->ensureArray($this->keywords),

            // Features — plain string array (not resource objects)
            'features'              => $this->whenLoaded(
                'features',
                fn () => $this->features->pluck('title')->values()->all(),
                []
            ),

            // Meta
            'duration'              => CourseDisplay::resolvedDuration(
                $this->program_type,
                $this->session_format,
                $this->duration
            ),
            'duration_value'        => CourseDisplay::isOneSession($this->program_type, $this->session_format) ? 1 : null,
            'duration_unit'         => CourseDisplay::isOneSession($this->program_type, $this->session_format) ? 'day' : null,
            'computed_duration_label' => CourseDisplay::resolvedDuration(
                $this->program_type,
                $this->session_format,
                $this->duration
            ),
            'training_hours'        => $this->training_hours,
            'target_audience'       => $this->target_audience,
            'language'              => $this->language,
            'level'                 => $this->level,
            'study_days'            => $this->study_days,
            'study_time'            => $this->study_time,
            'certificate'           => $this->certificate,
            'certificate_available' => (bool) $this->certificate_available,
            'certificate_type'      => $this->certificate_type,

            // Instructor
            'instructor_id'         => $this->instructor_id,
            'instructor'            => $this->buildInstructor(),
            'instructor_name'       => $this->buildInstructorName(),

            // Relations
            'track'                 => $this->whenLoaded('track', fn () => $this->track ? TrackResource::make($this->track) : null),

            // ── Ended-course computed fields ──────────────────────────────────
            // is_ended: published course whose end_date is in the past.
            // Does NOT mutate the DB status so the course stays visible publicly.
            'is_ended'              => CourseComputedStatus::isEnded($this->resource),
            'computed_status'       => CourseComputedStatus::computedStatus($this->resource),
            'status_label_ar'       => CourseComputedStatus::statusLabelAr($this->resource),

            'created_at'            => $this->created_at,
            'updated_at'            => $this->updated_at,
        ];
    }

    private function canSeeMeetingLink(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        // Admins and staff always see it
        if ($user->hasAnyRole(['admin', 'super_admin', 'tech_admin', 'executive_admin', 'instructor', 'teacher', 'programs_manager'])) {
            return true;
        }
        // Students only if enrolled in this course
        // Enrolled learners (whatever their role) see the meeting link of what they are enrolled in.
        return $user->registrations()
            ->where('course_id', $this->id)
            ->whereIn('status', ['active', 'registered', 'enrolled', 'completed'])
            ->exists();
    }

    private function buildInstructor(): ?array
    {
        if (! $this->relationLoaded('instructor') || ! $this->instructor) {
            return null;
        }

        $profile = $this->instructor;
        $user    = $profile->relationLoaded('user') ? $profile->user : null;

        $avatarPath = $user?->avatar ?? $profile->image;
        $photoUrl   = $this->resolveImageUrl($avatarPath);

        return [
            'id'                => $profile->id,
            'user_id'           => $profile->user_id,
            'name'              => $user?->name ?? $profile->name,
            'email'             => $user?->email,
            'role'              => $user?->role ?? 'instructor',
            'title'             => $profile->title,
            'bio'               => $profile->bio,
            'image'             => $photoUrl,
            'avatar_url'        => $photoUrl,
            'profile_photo_url' => $photoUrl,
        ];
    }

    private function buildInstructorName(): ?string
    {
        if (! $this->relationLoaded('instructor') || ! $this->instructor) {
            return null;
        }

        $user = $this->instructor->relationLoaded('user') ? $this->instructor->user : null;

        return $user?->name ?? $this->instructor->name;
    }

    private function resolveImageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        return asset('storage/' . ltrim($path, '/'));
    }

    /**
     * Derive a human-readable schedule time string.
     * Priority: study_time → "start_time – end_time" → null.
     */
    private function resolveScheduleTime(): ?string
    {
        if (! empty($this->study_time)) {
            return $this->study_time;
        }
        if (! empty($this->start_time) && ! empty($this->end_time)) {
            return $this->start_time . ' – ' . $this->end_time;
        }
        if (! empty($this->start_time)) {
            return $this->start_time;
        }
        return null;
    }

    /** Guarantees the value is a PHP array regardless of whether it is already
     *  decoded (array), a JSON string (string), or null. */
    private function ensureArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
