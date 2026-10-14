<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\VolunteerApplicationConfirmationMail;
use App\Models\Department;
use App\Models\VolunteerOpportunity;
use App\Models\VolunteerRequest;
use App\Services\EmailService;
use App\Services\NotificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Public volunteer opportunities: browse, read one, apply.
 *
 * Only a PUBLISHED opportunity with an open deadline is listed; a published or closed one can
 * still be opened so a visitor sees why applications are closed; a draft or archived one does
 * not exist as far as the public is concerned (404, identical to an unknown id).
 *
 * What is exposed is a fixed allow-list of presentation fields. Nothing internal ever leaves
 * here: not the originating incoming request, not who created or published it, not applicant
 * counts, not notes. Applying reuses the existing volunteer application (volunteer_requests) and
 * its existing confirmation and HR notification, adding only the link to the opportunity.
 */
class VolunteerOpportunityController extends Controller
{
    private const CLOSING_SOON_DAYS = 7;

    // GET /api/volunteer-opportunities
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'location_mode' => ['sometimes', 'nullable', Rule::in(VolunteerOpportunity::LOCATION_MODES)],
            'sort' => ['sometimes', 'nullable', Rule::in(['newest', 'deadline', 'title'])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $visible = VolunteerOpportunity::query()->publiclyVisible();

        $query = (clone $visible)
            ->when(! empty($v['q']), function ($q) use ($v) {
                $term = trim($v['q']);
                $q->where(fn ($w) => $w->where('title', 'like', "%{$term}%")->orWhere('summary', 'like', "%{$term}%"));
            })
            ->when(! empty($v['department_id']), fn ($q) => $q->where('department_id', (int) $v['department_id']))
            ->when(! empty($v['location_mode']), fn ($q) => $q->where('location_mode', $v['location_mode']))
            ->with('department:id,name,name_ar');

        match ($v['sort'] ?? 'newest') {
            'deadline' => $query->orderByRaw('closes_at IS NULL')->orderBy('closes_at')->orderByDesc('published_at'),
            'title' => $query->orderBy('title'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };

        $page = $query->paginate((int) ($v['per_page'] ?? 12));

        // Filter options reflect what is actually available, not every department.
        $deptCounts = (clone $visible)->whereNotNull('department_id')->selectRaw('department_id, COUNT(*) as c')->groupBy('department_id')->pluck('c', 'department_id');
        $departments = Department::query()->whereIn('id', $deptCounts->keys())->get(['id', 'name', 'name_ar'])
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name_ar ?: $d->name, 'count' => (int) $deptCounts[$d->id]])->sortBy('name')->values();

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn ($o) => $this->summary($o))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'filters' => [
                'departments' => $departments,
                'location_modes' => collect(VolunteerOpportunity::LOCATION_LABELS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            ],
        ]);
    }

    // GET /api/volunteer-opportunities/{id}
    public function show(int $id): JsonResponse
    {
        $o = $this->findViewable($id);
        if (! $o) {
            return $this->notFound();
        }

        return response()->json(['success' => true, 'data' => $this->detail($o)]);
    }

    // POST /api/volunteer-opportunities/{id}/applications
    public function apply(Request $request, int $id): JsonResponse
    {
        $o = $this->findViewable($id);
        if (! $o) {
            return $this->notFound();
        }

        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'experience_level' => ['required', 'string', 'max:50'],
            'availability' => ['required', 'string', 'max:100'],
            'skills' => ['nullable', 'string', 'max:2000'],
            'previous_experience' => ['nullable', 'string', 'max:3000'],
            'motivation' => ['required', 'string', 'min:20', 'max:3000'],
            'agree_terms' => ['accepted'],
        ], [
            'full_name.required' => 'الاسم الكامل مطلوب.',
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
            'phone.required' => 'رقم الجوال مطلوب.',
            'country.required' => 'الدولة مطلوبة.',
            'city.required' => 'المدينة مطلوبة.',
            'gender.required' => 'الجنس مطلوب.',
            'gender.in' => 'قيمة الجنس غير صالحة.',
            'experience_level.required' => 'مستوى الخبرة مطلوب.',
            'availability.required' => 'التوفر مطلوب.',
            'motivation.required' => 'اكتب لماذا ترغب في التطوع.',
            'motivation.min' => 'اكتب 20 حرفًا على الأقل في سبب التطوع.',
            'agree_terms.accepted' => 'يجب الموافقة على الشروط للمتابعة.',
        ]);

        $email = mb_strtolower(trim($data['email']));

        try {
            $application = DB::transaction(function () use ($o, $data, $email) {
                // Lock the opportunity so a close/unpublish and a submission cannot interleave.
                $locked = VolunteerOpportunity::query()->lockForUpdate()->find($o->id);
                if (! $locked || ! $locked->acceptsApplications()) {
                    return null;
                }

                if (VolunteerRequest::query()->where('opportunity_id', $locked->id)->where('email', $email)->exists()) {
                    return false;
                }

                $locked->loadMissing('department:id,name,name_ar');

                return VolunteerRequest::create([
                    'opportunity_id' => $locked->id,
                    'full_name' => trim($data['full_name']),
                    'email' => $email,
                    'phone' => $data['phone'],
                    'country' => $data['country'],
                    'city' => $data['city'],
                    'gender' => $data['gender'],
                    // The field the visitor is applying to is the opportunity's own department.
                    'desired_department' => $locked->department ? ($locked->department->name_ar ?: $locked->department->name) : 'غير محدد',
                    'experience_level' => $data['experience_level'],
                    'skills' => $data['skills'] ?? null,
                    'availability' => $data['availability'],
                    'motivation' => $data['motivation'],
                    'previous_experience' => $data['previous_experience'] ?? null,
                    'status' => 'pending', // explicit review by HR is required; nothing is accepted or assigned here
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous submissions: the unique (opportunity_id, email) index lets exactly one through.
            return $this->duplicate();
        }

        if ($application === null) {
            return response()->json(['success' => false, 'message' => 'أُغلق باب التقديم على هذه الفرصة.'], 409);
        }
        if ($application === false) {
            return $this->duplicate();
        }

        // The same confirmation and HR notification the general volunteer form already sends. The
        // application is already stored, so a failure here must not turn into an error response: the
        // visitor would retry and be told they had already applied. It is reported instead.
        try {
            EmailService::dispatch(
                'volunteer_confirmation',
                $application->email,
                $application->full_name,
                null,
                new VolunteerApplicationConfirmationMail($application->full_name, $application->desired_department, now()->format('Y-m-d H:i')),
                'تم استلام طلب التطوع — منصة EMC',
            );
            NotificationService::volunteerRequestSubmitted($application->id, $application->full_name, $application->desired_department, $application);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم استلام طلبك. سيراجعه فريق الموارد البشرية ويتواصل معك.',
        ], 201);
    }

    // ── presentation (allow-list) ────────────────────────────────────────────

    private function findViewable(int $id): ?VolunteerOpportunity
    {
        return VolunteerOpportunity::query()
            ->where('id', $id)
            ->whereIn('status', [VolunteerOpportunity::STATUS_PUBLISHED, VolunteerOpportunity::STATUS_CLOSED])
            ->whereNotNull('published_at') // a closed opportunity keeps the moment it was published
            ->with('department:id,name,name_ar')
            ->first();
    }

    /** @return array<string, mixed> */
    private function summary(VolunteerOpportunity $o): array
    {
        $dept = $o->department;
        $daysLeft = $o->closes_at ? max(0, (int) now()->startOfDay()->diffInDays($o->closes_at->startOfDay(), false)) : null;

        return [
            'id' => $o->id,
            'title' => $o->title,
            'summary' => $o->summary,
            'field' => $dept ? ($dept->name_ar ?: $dept->name) : null,
            'department_id' => $o->department_id,
            'location_mode' => $o->location_mode,
            'location_label' => VolunteerOpportunity::LOCATION_LABELS[$o->location_mode] ?? null,
            'commitment' => $o->availability,
            'duration' => $o->duration,
            'volunteers_needed' => $o->volunteers_needed,
            'closes_at' => $o->closes_at?->toDateString(),
            'days_left' => $daysLeft,
            'published_at' => $o->published_at?->toIso8601String(),
            'status' => $this->publicStatus($o),
            'status_label' => ['open' => 'التقديم مفتوح', 'closing_soon' => 'يغلق قريبًا', 'closed' => 'أُغلق التقديم'][$this->publicStatus($o)],
        ];
    }

    /** @return array<string, mixed> */
    private function detail(VolunteerOpportunity $o): array
    {
        return $this->summary($o) + [
            'responsibilities' => array_values((array) $o->responsibilities),
            'required_skills' => array_values((array) $o->required_skills),
            'preferred_skills' => array_values((array) $o->preferred_skills),
            'start_date' => $o->start_date?->toDateString(),
            'application_instructions' => $o->application_instructions,
            'accepting_applications' => $o->acceptsApplications(),
            'closed_reason' => $o->acceptsApplications() ? null : ($o->status === VolunteerOpportunity::STATUS_CLOSED ? 'closed' : 'deadline_passed'),
        ];
    }

    private function publicStatus(VolunteerOpportunity $o): string
    {
        if (! $o->acceptsApplications()) {
            return 'closed';
        }

        return $o->closes_at && now()->startOfDay()->diffInDays($o->closes_at->startOfDay(), false) <= self::CLOSING_SOON_DAYS ? 'closing_soon' : 'open';
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'هذه الفرصة غير متاحة.'], 404);
    }

    private function duplicate(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'لقد قدّمت على هذه الفرصة مسبقًا. سيتواصل معك فريق الموارد البشرية.'], 409);
    }
}
