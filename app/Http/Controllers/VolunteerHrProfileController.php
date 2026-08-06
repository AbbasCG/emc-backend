<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVolunteerHrProfileRequest;
use App\Http\Resources\VolunteerHrProfileResource;
use App\Models\VolunteerHrProfile;
use App\Services\NotificationService;
use App\Services\VolunteerApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VolunteerHrProfileController extends Controller
{
    public function __construct(private readonly VolunteerApprovalService $approval)
    {
    }

    // GET /api/volunteer/hr-profile — the current user's own profile, if any.
    public function show(Request $request): JsonResponse
    {
        $profile = VolunteerHrProfile::where('user_id', $request->user()->id)
            ->with(['department', 'reviewer', 'approver'])
            ->latest()
            ->first();

        return response()->json([
            'success' => true,
            'data'    => $profile ? new VolunteerHrProfileResource($profile) : null,
        ]);
    }

    // POST /api/volunteer/hr-profile
    public function store(StoreVolunteerHrProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $duplicate = $this->approval->findDuplicate($data['email'], $user->id);
        if ($duplicate) {
            return response()->json(['success' => false, 'code' => $duplicate['code'], 'message' => $duplicate['message']], 422);
        }

        $cvPath = $cvOriginalName = $cvMimeType = $cvSize = null;
        if ($request->hasFile('cv_file')) {
            $file = $request->file('cv_file');
            $ext  = $file->getClientOriginalExtension();
            $cvPath         = $file->storeAs('volunteer-hr-cvs', Str::random(40) . '.' . $ext, 'private');
            $cvOriginalName = $file->getClientOriginalName();
            $cvMimeType     = $file->getClientMimeType();
            $cvSize         = $file->getSize();
        }

        $photoPath = null;
        if ($request->hasFile('profile_photo')) {
            $photoPath = $request->file('profile_photo')->store('volunteer-hr-photos', 'public');
        }

        $profile = VolunteerHrProfile::create([
            'user_id'          => $user->id,
            'full_name'        => $data['full_name'],
            'email'            => $data['email'],
            'normalized_email' => VolunteerHrProfile::normalizeEmail($data['email']),
            'phone'              => $data['phone'],
            'phone_country_code' => $data['phone_country_code'] ?? null,
            'country'            => $data['country'] ?? null,
            'country_code'       => $data['country_code'] ?? null,
            'city'             => $data['city'] ?? null,
            'date_of_birth'    => $data['date_of_birth'] ?? null,
            'gender'           => $data['gender'] ?? null,
            'nationality'      => $data['nationality'] ?? null,
            'department_id'    => $data['department_id'],
            'job_title'        => $data['job_title'],
            'employment_type'  => $data['employment_type'] ?? null,
            'join_date'        => $data['join_date'],
            'availability'     => $data['availability'] ?? null,
            'weekly_hours'     => $data['weekly_hours'] ?? null,
            'skills'           => $data['skills'] ?? null,
            'languages'        => $data['languages'] ?? null,
            'education'        => $data['education'] ?? null,
            'experience'       => $data['experience'] ?? null,
            'motivation'       => $data['motivation'] ?? null,
            'cv_path'              => $cvPath,
            'cv_original_name'     => $cvOriginalName,
            'cv_mime_type'          => $cvMimeType,
            'cv_size'               => $cvSize,
            'profile_photo_path'   => $photoPath,
            'linkedin_url'         => $data['linkedin_url'] ?? null,
            'portfolio_url'        => $data['portfolio_url'] ?? null,
            'status'               => 'submitted',
            'submitted_at'         => now(),
        ]);

        NotificationService::volunteerHrProfileSubmitted($profile->id, $profile->full_name);

        // Load the same relations GET /volunteer/hr-profile eager-loads, so the
        // submit response is the identical canonical shape the page would see
        // on refresh — a fresh create() otherwise leaves `department` (etc.)
        // as an omitted key via Resource::whenLoaded(), not merely null.
        $profile->load(['department', 'reviewer', 'approver']);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال بياناتك بنجاح',
            'data'    => new VolunteerHrProfileResource($profile),
        ], 201);
    }

    // PUT /api/volunteer/hr-profile/{volunteerHrProfile}
    public function update(StoreVolunteerHrProfileRequest $request, VolunteerHrProfile $volunteerHrProfile): JsonResponse
    {
        if ($volunteerHrProfile->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بهذا الإجراء.'], 403);
        }
        if (! in_array($volunteerHrProfile->status, ['draft', 'rejected'], true)) {
            return response()->json(['success' => false, 'message' => 'لا يمكن تعديل الطلب في حالته الحالية.'], 422);
        }

        $data = $request->validated();

        if ($request->hasFile('cv_file')) {
            $file = $request->file('cv_file');
            $ext  = $file->getClientOriginalExtension();
            $data['cv_path']          = $file->storeAs('volunteer-hr-cvs', Str::random(40) . '.' . $ext, 'private');
            $data['cv_original_name'] = $file->getClientOriginalName();
            $data['cv_mime_type']     = $file->getClientMimeType();
            $data['cv_size']          = $file->getSize();
        }
        if ($request->hasFile('profile_photo')) {
            $data['profile_photo_path'] = $request->file('profile_photo')->store('volunteer-hr-photos', 'public');
        }

        $data['normalized_email'] = VolunteerHrProfile::normalizeEmail($data['email']);
        $data['status']       = 'submitted';
        $data['submitted_at'] = now();
        unset($data['cv_file'], $data['profile_photo'], $data['confirmed']);

        $volunteerHrProfile->update($data);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث وإعادة إرسال بياناتك بنجاح',
            'data'    => new VolunteerHrProfileResource($volunteerHrProfile->fresh(['department', 'reviewer', 'approver'])),
        ]);
    }
}
