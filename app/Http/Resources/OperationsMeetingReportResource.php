<?php

namespace App\Http\Resources;

use App\Models\MeetingReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The ONE shape the operations "تقارير الاجتماعات" page receives, for both the
 * list and the details view.
 *
 * `meeting_reports` is shared by two writers: the department ops report
 * (Operations\MeetingReportController, always has a title/date/department) and
 * the per-meeting quick report from the meetings lounge
 * (Admin\MeetingController::storeReport), which historically stored a row with
 * NULL title, meeting_date, department_id and created_by and put its text in
 * the quick-report columns. Returning the raw model handed the page those NULLs
 * and crashed it. This resource guarantees what the page can rely on:
 *
 *   - `title` and `meeting_date` are never null (fallback to the linked
 *     meeting, then to a generic title / the creation date);
 *   - the four narrative sections are coalesced across both column sets;
 *   - `department` and `author` are null or a small {id,name} - never missing;
 *   - only fields the page needs are exposed (no created_by, updated_at,
 *     raw quick-report columns or foreign keys).
 *
 * @mixin MeetingReport
 */
class OperationsMeetingReportResource extends JsonResource
{
    /** Include narrative sections, extras and per-member ratings (details view). */
    public bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    /** @var array<string, mixed>|null Attendance summary computed by the controller. */
    public ?array $attendance = null;

    public function attendance(?array $attendance): static
    {
        $this->attendance = $attendance;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $meeting = $this->relationLoaded('meeting') ? $this->meeting : null;
        $department = $this->department ?? $meeting?->department;

        $date = $this->meeting_date ?? $meeting?->meeting_date ?? $this->created_at;

        $title = trim((string) $this->title);
        if ($title === '') {
            $meetingTitle = trim((string) $meeting?->title);
            $title = $meetingTitle !== '' ? "تقرير دوري — {$meetingTitle}" : 'تقرير اجتماع';
        }

        $base = [
            'id'            => $this->id,
            'title'         => $title,
            'meeting_date'  => $date?->toDateString(),
            'department'    => $department ? ['id' => $department->id, 'name' => $department->name_ar ?: $department->name] : null,
            'author'        => $this->author ? ['id' => $this->author->id, 'name' => $this->author->name] : null,
            // The source meeting, when this report was filed from the lounge for a scheduled meeting.
            'meeting'       => $meeting ? ['id' => $meeting->id, 'title' => $meeting->title, 'type' => $meeting->type] : null,
            'attendance'    => $this->attendance,
            'ratings_count' => (int) ($this->ratings_count ?? 0),
            'attended_count' => (int) ($this->attended_count ?? 0),
            'created_at'    => $this->created_at?->toIso8601String(),
        ];

        if (! $this->detailed) {
            return $base;
        }

        return $base + [
            'achieved'     => $this->text($this->achieved ?? $this->completed_tasks_summary),
            'planned'      => $this->text($this->planned ?? $this->next_month_goals),
            'needs'        => $this->text($this->needs ?? $this->executive_requests),
            'needs_reason' => $this->text($this->needs_reason ?? $this->challenges),
            'decisions'    => $this->text($this->decisions),
            'notes'        => $this->text($this->notes),
            'extras'       => $this->extras(),
            'ratings'      => $this->relationLoaded('ratings')
                ? $this->ratings->map(fn ($r) => [
                    'id'        => $r->id,
                    'attended'  => (bool) $r->attended,
                    'scores'    => is_array($r->scores) ? $r->scores : null,
                    'avg_score' => $r->avg_score !== null ? (float) $r->avg_score : null,
                    'note'      => $this->text($r->note),
                    'user'      => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * Quick-report-only figures, present only when the row actually has them.
     *
     * @return array<string, mixed>|object
     */
    private function extras(): array|object
    {
        $extras = [
            'team_commitment_score'  => $this->team_commitment_score,
            'team_commitment_notes'  => $this->text($this->team_commitment_notes),
            'kpi_percentage'         => $this->kpi_percentage,
            'top_interactive_member' => $this->text($this->top_interactive_member),
            'improvement_suggestions' => $this->text($this->improvement_suggestions),
            'absentees'              => is_array($this->absentees) ? array_values(array_filter(array_map('strval', $this->absentees))) : [],
        ];

        $extras = array_filter($extras, fn ($v) => $v !== null && $v !== [] && $v !== '');

        // An empty set must serialize as {} (not []) so the client always gets an object.
        return $extras === [] ? (object) [] : $extras;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
