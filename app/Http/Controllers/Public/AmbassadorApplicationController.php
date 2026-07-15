<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAmbassadorApplicationRequest;
use App\Http\Resources\AmbassadorApplicationResource;
use App\Mail\AmbassadorApplicationConfirmationMail;
use App\Jobs\ProcessAmbassadorFile;
use App\Models\AmbassadorApplication;
use App\Models\AmbassadorApplicationFile;
use App\Models\AmbassadorApplicationStatusHistory;
use App\Services\EmailService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AmbassadorApplicationController extends Controller
{
    /**
     * Submit a full application.
     *
     * Duplicate-detection strategy:
     *  - Draft record:              reuse and finalize (never treat as duplicate)
     *  - Partial failed submission: has is_draft=false but no status-history row → reuse and finalize
     *  - Valid submitted:           has is_draft=false AND at least one status-history row → 409
     *  - Cancelled / rejected:      always allow reapplication
     */
    public function store(StoreAmbassadorApplicationRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $email = $request->email;

            // Look for any non-cancelled record from the same email in the last 30 days
            $existing = AmbassadorApplication::where('email', $email)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->where('created_at', '>=', now()->subDays(30))
                ->latest()
                ->first();

            if ($existing) {
                $hasHistory = $existing->statusHistory()->exists();
                $isDraft    = (bool) $existing->is_draft;

                // True duplicate: was fully submitted (has history, not a draft)
                if (! $isDraft && $hasHistory) {
                    return response()->json([
                        'success' => false,
                        'code'    => 'APPLICATION_ALREADY_SUBMITTED',
                        'message' => 'لقد تم إرسال طلب سابق بهذا البريد الإلكتروني.',
                        'data'    => [
                            'reference_number' => $this->referenceNumber($existing),
                        ],
                    ], 409);
                }

                // Recoverable: either a draft or a failed partial submission
                // → update it and finalize in the same transaction below
                $applicationToFinalize = $existing;
            } else {
                $applicationToFinalize = null;
            }

            $fileRecords = [];

            $application = DB::transaction(function () use ($request, $data, $applicationToFinalize, &$fileRecords) {
                $cvPath           = $this->uploadFile($request, 'cv_file', 'ambassador/cv');
                $studentIdPath    = $this->uploadFile($request, 'student_id_file', 'ambassador/student-ids');
                $photoPath        = $this->uploadFile($request, 'photo_file', 'ambassador/photos');
                $certificatePaths = $this->uploadMultipleFiles($request, 'certificate_files', 'ambassador/certificates');

                $fields = array_merge($data, [
                    'status'   => 'new',
                    'is_draft' => false,
                ]);

                if ($cvPath)            $fields['cv_path']           = $cvPath;
                if ($studentIdPath)     $fields['student_id_path']   = $studentIdPath;
                if ($photoPath)         $fields['photo_path']        = $photoPath;
                if ($certificatePaths)  $fields['certificate_paths'] = $certificatePaths;

                if ($applicationToFinalize) {
                    // Finalize the existing draft / partial record
                    $fields['draft_token'] = null;
                    $applicationToFinalize->update($fields);
                    $app = $applicationToFinalize->fresh();
                } else {
                    $fields['created_by'] = $request->user()?->id;
                    $app = AmbassadorApplication::create($fields);
                }

                // Create initial status-history row (idempotent — skip if already exists)
                if (! AmbassadorApplicationStatusHistory::where('ambassador_application_id', $app->id)->exists()) {
                    AmbassadorApplicationStatusHistory::create([
                        'ambassador_application_id' => $app->id,
                        'from_status'               => null,
                        'to_status'                 => 'new',
                        'reason'                    => 'تقديم الطلب',
                        'changed_at'                => now(),
                    ]);
                }

                // Record uploaded files in ambassador_application_files table
                $uploadedBy = $request->user()?->id;
                $uploadedAt = now();

                if ($cvPath && $request->hasFile('cv_file')) {
                    $fileRecords[] = $this->createFileRecord($app->id, 'cv', $request->file('cv_file'), $cvPath, $uploadedBy, $uploadedAt);
                }
                if ($studentIdPath && $request->hasFile('student_id_file')) {
                    $fileRecords[] = $this->createFileRecord($app->id, 'student_id', $request->file('student_id_file'), $studentIdPath, $uploadedBy, $uploadedAt);
                }
                if ($photoPath && $request->hasFile('photo_file')) {
                    $fileRecords[] = $this->createFileRecord($app->id, 'photo', $request->file('photo_file'), $photoPath, $uploadedBy, $uploadedAt);
                }
                if ($certificatePaths && $request->hasFile('certificate_files')) {
                    $certFiles = $request->file('certificate_files');
                    foreach ($certFiles as $idx => $certFile) {
                        $certPath = $certificatePaths[$idx] ?? null;
                        if ($certFile && $certPath) {
                            $fileRecords[] = $this->createFileRecord($app->id, 'certificate', $certFile, $certPath, $uploadedBy, $uploadedAt);
                        }
                    }
                }

                return $app;
            });

            // Dispatch compression jobs for each recorded file (post-commit)
            foreach ($fileRecords as $fileRecord) {
                try {
                    ProcessAmbassadorFile::dispatch($fileRecord->id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to dispatch ProcessAmbassadorFile', ['file_id' => $fileRecord->id, 'error' => $e->getMessage()]);
                }
            }

            // Post-commit: notifications and emails (failures do NOT roll back the submission)
            $this->dispatchNotifications($application);

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال طلبك بنجاح.',
                'data'    => [
                    'application_id'           => $application->id,
                    'reference_number'         => $this->referenceNumber($application),
                    'status'                   => $application->status,
                    'submitted_at'             => $application->updated_at->toIso8601String(),
                    'email_confirmation_queued' => true,
                ],
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Ambassador application submission failed', [
                'email'   => $request->email ?? null,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ غير متوقع أثناء إرسال الطلب. يرجى المحاولة مرة أخرى.',
            ], 500);
        }
    }

    /**
     * Save draft (returns draft_token for later resumption).
     */
    public function saveDraft(Request $request): JsonResponse
    {
        $request->validate([
            'draft_token' => ['nullable', 'string', 'max:80'],
            'step'        => ['nullable', 'integer', 'min:1', 'max:11'],
        ]);

        $token = $request->draft_token ?? Str::random(64);

        $existing = AmbassadorApplication::where('draft_token', $token)->where('is_draft', true)->first();

        $fieldsToSave = $this->extractDraftFields($request);

        if ($existing) {
            $existing->update($fieldsToSave);
            $application = $existing;
        } else {
            $application = AmbassadorApplication::create(array_merge($fieldsToSave, [
                'status'      => 'new',
                'is_draft'    => true,
                'draft_token' => $token,
                'created_by'  => $request->user()?->id,
            ]));
        }

        return response()->json([
            'success'     => true,
            'draft_token' => $token,
            'id'          => $application->id,
        ]);
    }

    /**
     * Resume a draft by token.
     */
    public function getDraft(Request $request, string $token): JsonResponse
    {
        $draft = AmbassadorApplication::where('draft_token', $token)
            ->where('is_draft', true)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data'    => new AmbassadorApplicationResource($draft),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function referenceNumber(AmbassadorApplication $app): string
    {
        return 'AMB-' . $app->created_at->format('Y') . '-' . str_pad((string) $app->id, 6, '0', STR_PAD_LEFT);
    }

    private function dispatchNotifications(AmbassadorApplication $application): void
    {
        try {
            EmailService::dispatch(
                'ambassador_confirmation',
                $application->email,
                $application->full_name,
                null,
                new AmbassadorApplicationConfirmationMail(
                    $application->full_name,
                    $this->referenceNumber($application),
                    $application->updated_at->format('Y-m-d H:i'),
                ),
                'تم استلام طلبك كسفير للتحول الرقمي — منصة EMC',
            );
        } catch (\Throwable $e) {
            Log::error('Ambassador confirmation email failed', [
                'application_id' => $application->id,
                'error'          => $e->getMessage(),
            ]);
        }

        try {
            NotificationService::ambassadorApplicationSubmitted(
                $application->id,
                $application->full_name,
                $application->university_name,
            );
        } catch (\Throwable $e) {
            Log::error('Ambassador admin notification failed', [
                'application_id' => $application->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    private function createFileRecord(
        int $applicationId,
        string $category,
        \Illuminate\Http\UploadedFile $file,
        string $storedPath,
        ?int $uploadedBy,
        \Carbon\Carbon $uploadedAt
    ): AmbassadorApplicationFile {
        return AmbassadorApplicationFile::create([
            'ambassador_application_id' => $applicationId,
            'category'           => $category,
            'original_name'      => $file->getClientOriginalName(),
            'stored_name'        => basename($storedPath),
            'mime_type'          => $file->getClientMimeType(),
            'extension'          => strtolower($file->getClientOriginalExtension()),
            'original_size'      => $file->getSize(),
            'optimized_size'     => null,
            'compression_ratio'  => null,
            'checksum'           => hash_file('sha256', $file->getRealPath()),
            'disk'               => 'private',
            'path'               => $storedPath,
            'thumbnail_path'     => null,
            'preview_path'       => null,
            'processing_status'  => 'uploaded',
            'uploaded_by'        => $uploadedBy,
            'uploaded_at'        => $uploadedAt,
        ]);
    }

    private function uploadFile(Request $request, string $field, string $folder): ?string
    {
        if (! $request->hasFile($field)) {
            return null;
        }

        $file = $request->file($field);
        $ext  = strtolower($file->getClientOriginalExtension());
        $safe = Str::uuid() . '.' . $ext;

        return $file->storeAs($folder, $safe, 'private');
    }

    private function uploadMultipleFiles(Request $request, string $field, string $folder): array
    {
        if (! $request->hasFile($field)) {
            return [];
        }

        $paths = [];
        foreach ($request->file($field) as $file) {
            $ext  = strtolower($file->getClientOriginalExtension());
            $safe = Str::uuid() . '.' . $ext;
            $paths[] = $file->storeAs($folder, $safe, 'private');
        }

        return $paths;
    }

    private function extractDraftFields(Request $request): array
    {
        $allowed = [
            'full_name', 'email', 'mobile_phone', 'nationality', 'gender', 'date_of_birth', 'country', 'city',
            'university_name', 'university_type', 'faculty', 'major', 'study_year', 'student_number',
            'student_status', 'expected_graduation_date',
            'motivation_why', 'contribution_what', 'expected_gain', 'biggest_achievement', 'biggest_challenge',
            'has_volunteer_experience', 'has_event_organization_experience', 'has_teaching_experience',
            'has_student_club_involvement', 'social_instagram', 'social_linkedin', 'social_facebook',
            'social_tiktok', 'social_github', 'website_url', 'followers_count',
            'interests', 'skills', 'has_led_team', 'has_organized_events', 'events_attendees_count',
            'has_represented_university', 'weekly_hours_available', 'can_attend_university_events',
            'can_represent_emc_outside', 'can_travel', 'owns_laptop', 'has_stable_internet',
            'can_attend_weekly_meetings', 'volunteer_experience_types', 'certifications',
        ];

        return collect($request->only($allowed))
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->toArray();
    }
}
