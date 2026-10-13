<?php

namespace App\Services;

use App\Models\HrRequest;
use App\Models\User;
use App\Models\VolunteerOpportunity;
use App\Support\SkillText;
use Illuminate\Support\Facades\DB;

/**
 * Builds and stores DRAFT volunteer opportunities from an HR incoming request.
 *
 * A draft is a starting point for HR to edit. It is never published here: `status` and
 * `published_at` are not mass-assignable and nothing in this class sets them. There is at
 * most one draft per request (a unique index backs this up), so repeated clicks cannot create
 * duplicates.
 */
class VolunteerOpportunityDraftService
{
    public const DEFAULT_REQUEST_TYPE = 'احتياج وظيفي / نقص أعضاء';

    /**
     * Auto-filled values for the review form. Nothing is saved.
     *
     * @return array<string, mixed>
     */
    public function suggest(HrRequest $request): array
    {
        $request->loadMissing('department:id,name,name_ar');
        $deptName = $request->department?->name_ar ?: $request->department?->name;
        $role = $this->roleName($request);
        $skills = SkillText::phrases($request->skills_and_specs, 12);
        $count = max(1, (int) $request->required_count);
        $who = $count > 1 ? "{$count} متطوعين" : 'متطوع/ة';

        $summary = $deptName
            ? "تبحث {$this->deptPhrase($deptName)} في EMC عن {$who} للمساهمة في ".($role ? "دور «{$role}»" : 'دعم أعمال الإدارة').' وفق احتياجات الفريق.'
            : "تبحث EMC عن {$who} للمساهمة في ".($role ? "دور «{$role}»" : 'دعم أعمال الفريق').'.';

        $responsibilities = $this->lines($request->notes);
        if ($responsibilities === []) {
            $responsibilities = [$deptName ? "دعم أعمال {$this->deptPhrase($deptName)} وفق احتياجات الفريق." : 'دعم أعمال الفريق وفق احتياجاته.'];
        }

        return [
            'incoming_request_id' => $request->id,
            'department_id' => $request->department_id,
            'department_name' => $deptName,
            'title' => 'فرصة تطوعية: '.($role ?: 'متطوع/ة').($deptName ? " ل{$this->deptPhrase($deptName)}" : ''),
            'summary' => $summary,
            'responsibilities' => $responsibilities,
            'required_skills' => $skills,
            'preferred_skills' => [],
            'volunteers_needed' => $count,
            'availability' => null,
            'location_mode' => null,
            'start_date' => null,
            'application_instructions' => 'قدّم طلب التطوع عبر المنصة، وسيتواصل معك فريق الموارد البشرية بعد مراجعة طلبك.',
        ];
    }

    public function existing(HrRequest $request): ?VolunteerOpportunity
    {
        return $request->opportunityDraft()->first();
    }

    /**
     * @param  array<string, mixed>  $data  validated form values
     */
    public function create(HrRequest $request, array $data, User $by): VolunteerOpportunity
    {
        return DB::transaction(function () use ($request, $data, $by) {
            $draft = new VolunteerOpportunity($this->clean($data));
            $draft->incoming_request_id = $request->id;
            $draft->department_id = $data['department_id'] ?? $request->department_id;
            $draft->created_by = $by->id;
            $draft->updated_by = $by->id;
            $draft->save(); // status defaults to 'draft', published_at stays null

            return $draft->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(VolunteerOpportunity $draft, array $data, User $by): VolunteerOpportunity
    {
        $draft->fill($this->clean($data));
        if (array_key_exists('department_id', $data)) {
            $draft->department_id = $data['department_id'];
        }
        $draft->updated_by = $by->id;
        $draft->save();

        return $draft->fresh();
    }

    /** @return array<string, mixed> */
    public function present(VolunteerOpportunity $d): array
    {
        return [
            'id' => $d->id,
            'incoming_request_id' => $d->incoming_request_id,
            'department_id' => $d->department_id,
            'title' => $d->title,
            'summary' => $d->summary,
            'responsibilities' => $d->responsibilities ?? [],
            'required_skills' => $d->required_skills ?? [],
            'preferred_skills' => $d->preferred_skills ?? [],
            'volunteers_needed' => $d->volunteers_needed,
            'availability' => $d->availability,
            'location_mode' => $d->location_mode,
            'start_date' => $d->start_date?->toDateString(),
            'application_instructions' => $d->application_instructions,
            'status' => $d->status,
            'is_published' => $d->published_at !== null,
            'created_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Only the editable fields, with blank list entries removed. @param array<string, mixed> $data */
    private function clean(array $data): array
    {
        $out = array_intersect_key($data, array_flip([
            'title', 'summary', 'responsibilities', 'required_skills', 'preferred_skills', 'volunteers_needed',
            'availability', 'location_mode', 'start_date', 'application_instructions',
        ]));
        foreach (['responsibilities', 'required_skills', 'preferred_skills'] as $list) {
            if (array_key_exists($list, $out)) {
                $out[$list] = array_values(array_filter(array_map(fn ($x) => trim((string) $x), (array) $out[$list]), fn ($x) => $x !== ''));
            }
        }

        return $out;
    }

    /** The role named in the request, unless it is just the generic default label. */
    private function roleName(HrRequest $r): ?string
    {
        $type = trim((string) $r->request_type);

        return $type === '' || $type === self::DEFAULT_REQUEST_TYPE || str_contains($type, 'احتياج وظيفي') ? null : $type;
    }

    /** "إدارة التسويق" stays; a bare name becomes "إدارة X". */
    private function deptPhrase(string $name): string
    {
        return str_starts_with($name, 'إدارة') || str_starts_with($name, 'قسم') ? $name : "إدارة {$name}";
    }

    /** @return array<int, string> */
    private function lines(?string $text): array
    {
        return array_values(array_filter(array_map(fn ($l) => trim(trim($l), "-•* \t"), preg_split('/\r\n|\r|\n/', (string) $text) ?: []), fn ($l) => $l !== ''));
    }
}
