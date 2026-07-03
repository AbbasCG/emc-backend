<?php

namespace App\Services\Certificate;

use App\Events\Certificate\CertificateCreated;
use App\Jobs\GenerateCertificatePdfJob;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\LearningPath;
use App\Models\User;
use App\Models\Workshop;
use App\Services\ActivityLogger;
use App\Services\CertificateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent automation service for certificate issuance.
 *
 * Every public method is safe to call multiple times — if a certificate
 * already exists for the user/context combination it returns the existing
 * record and logs a "duplicate prevented" notice without creating a second row.
 */
class CertificateAutomationService
{
    public function __construct(
        private readonly CertificateService $certService,
    ) {}

    // ── Public entry points ───────────────────────────────────────────────────

    public function issueForCourseCompletion(User $user, Course $course): AutomationResult
    {
        $type       = $course->certificate_type ?? 'course_completion';
        $templateId = $this->resolveTemplate($course->certificate_template_id ?? null);

        return $this->issueAtomically(
            $user,
            $type,
            relatedType: 'course',
            relatedId:   $course->id,
            context:     ['course_id' => $course->id],
            attributes:  [
                'user_id'                 => $user->id,
                'course_id'               => $course->id,
                'certificate_type'        => $type,
                'certificate_template_id' => $templateId,
                'title'                   => 'شهادة إتمام: ' . $course->title,
                'recipient_name'          => $user->name,
                'recipient_email'         => $user->email,
                'status'                  => 'pending_generation',
                'pdf_generation_status'   => 'pending',
                'related_type'            => 'course',
                'related_id'              => $course->id,
            ],
        );
    }

    public function issueForWorkshopCompletion(User $user, Workshop $workshop): AutomationResult
    {
        $type       = 'workshop_attendance';
        $templateId = $this->resolveTemplate(null);

        return $this->issueAtomically(
            $user,
            $type,
            relatedType: 'workshop',
            relatedId:   $workshop->id,
            context:     ['workshop_id' => $workshop->id],
            attributes:  [
                'user_id'                 => $user->id,
                'workshop_id'             => $workshop->id,
                'certificate_type'        => $type,
                'certificate_template_id' => $templateId,
                'title'                   => 'شهادة حضور: ' . ($workshop->title ?? 'ورشة عمل'),
                'recipient_name'          => $user->name,
                'recipient_email'         => $user->email,
                'status'                  => 'pending_generation',
                'pdf_generation_status'   => 'pending',
                'related_type'            => 'workshop',
                'related_id'              => $workshop->id,
            ],
        );
    }

    public function issueForLearningPathCompletion(User $user, LearningPath $learningPath): AutomationResult
    {
        $type       = 'learning_track';
        $templateId = $this->resolveTemplate(null);

        return $this->issueAtomically(
            $user,
            $type,
            relatedType: 'learning_path',
            relatedId:   $learningPath->id,
            context:     ['learning_path_id' => $learningPath->id],
            attributes:  [
                'user_id'                 => $user->id,
                'track_id'                => $learningPath->id,
                'learning_path_id'        => $learningPath->id,
                'certificate_type'        => $type,
                'certificate_template_id' => $templateId,
                'title'                   => 'شهادة إتمام مسار: ' . $learningPath->name,
                'recipient_name'          => $user->name,
                'recipient_email'         => $user->email,
                'status'                  => 'pending_generation',
                'pdf_generation_status'   => 'pending',
                'related_type'            => 'learning_path',
                'related_id'              => $learningPath->id,
            ],
        );
    }

    /**
     * Generic manual-completion entry point (for admin triggers or future webhooks).
     *
     * $context must include: type, related_type, related_id, title
     */
    public function issueForManualCompletion(User $user, array $context): AutomationResult
    {
        $type        = $context['type'] ?? 'course_completion';
        $relatedType = $context['related_type'] ?? null;
        $relatedId   = $context['related_id'] ?? null;

        return DB::transaction(function () use ($user, $type, $relatedType, $relatedId, $context) {
            $existing = $this->findExisting($user->id, $type, $relatedType, $relatedId);
            if ($existing) {
                return $this->duplicateResult($existing, $user, $context);
            }

            $templateId = $this->resolveTemplate($context['template_id'] ?? null);

            $cert = Certificate::create([
                'user_id'                 => $user->id,
                'certificate_type'        => $type,
                'certificate_template_id' => $templateId,
                'title'                   => $context['title'] ?? 'شهادة',
                'recipient_name'          => $user->name,
                'recipient_email'         => $user->email,
                'status'                  => 'pending_generation',
                'pdf_generation_status'   => 'pending',
                'related_type'            => $relatedType,
                'related_id'              => $relatedId,
                'metadata'                => $context['metadata'] ?? null,
            ]);

            $this->dispatchPdf($cert);
            $this->logResult('created', $user, array_merge($context, ['certificate_id' => $cert->id]));

            event(new CertificateCreated($cert));

            return AutomationResult::created($cert);
        });
    }

    // ── Protected helpers ─────────────────────────────────────────────────────

    /**
     * Atomically check for duplicates and create a certificate if none exists.
     *
     * Wraps the SELECT + INSERT in a serializable-level DB transaction with a
     * SELECT FOR UPDATE, so concurrent listeners cannot both read "no duplicate"
     * and both proceed to insert.
     */
    /**
     * Atomically check for duplicates and create a certificate if none exists.
     *
     * Wraps the SELECT + INSERT in a DB transaction with SELECT FOR UPDATE so that
     * concurrent listeners cannot both read "no duplicate" and both proceed to insert.
     * Template resolution must happen BEFORE calling this method (outside the transaction)
     * to avoid holding the transaction open during potentially slow cache/DB lookups.
     */
    protected function issueAtomically(
        User    $user,
        string  $type,
        ?string $relatedType,
        ?int    $relatedId,
        array   $context,
        array   $attributes,
    ): AutomationResult {
        return DB::transaction(function () use ($user, $type, $relatedType, $relatedId, $context, $attributes) {
            // SELECT FOR UPDATE creates a gap lock that blocks concurrent inserts
            // for the same (user_id, certificate_type, related_type, related_id) range.
            $existing = Certificate::where('user_id', $user->id)
                ->where('certificate_type', $type)
                ->when($relatedType, fn ($q) => $q->where('related_type', $relatedType))
                ->when($relatedId,   fn ($q) => $q->where('related_id', $relatedId))
                ->where('status', '!=', 'revoked')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $this->duplicateResult($existing, $user, $context);
            }

            $cert = Certificate::create($attributes);

            $this->dispatchPdf($cert);
            $this->logResult('created', $user, array_merge($context, ['certificate_id' => $cert->id]));
            event(new CertificateCreated($cert));

            return AutomationResult::created($cert);
        });
    }

    /**
     * @deprecated Use issueAtomically() for all new automation paths.
     * Kept for issueForManualCompletion() which has different attribute shape.
     */
    protected function findExisting(int $userId, string $type, ?string $relatedType, ?int $relatedId): ?Certificate
    {
        return Certificate::where('user_id', $userId)
            ->where('certificate_type', $type)
            ->when($relatedType, fn ($q) => $q->where('related_type', $relatedType))
            ->when($relatedId, fn ($q) => $q->where('related_id', $relatedId))
            ->where('status', '!=', 'revoked')
            ->lockForUpdate()
            ->first();
    }

    protected function dispatchPdf(Certificate $cert): void
    {
        // afterCommit() holds the job in the queue until the surrounding DB transaction commits.
        // Without this, a transaction rollback leaves an orphaned job referencing a non-existent row.
        GenerateCertificatePdfJob::dispatch($cert)->onQueue(config('certificates.queue', 'certificates'))->afterCommit();
    }

    protected function resolveTemplate(?int $templateId): ?int
    {
        if ($templateId) {
            return $templateId;
        }

        return \App\Models\CertificateTemplate::where('is_default', true)
            ->where('is_active', true)
            ->value('id')
            ?? \App\Models\CertificateTemplate::where('designer_mode', true)
                ->where('is_active', true)
                ->orderBy('id')
                ->value('id');
    }

    protected function duplicateResult(Certificate $existing, User $user, array $context): AutomationResult
    {
        Log::notice('[CertificateAutomation] Duplicate prevented — certificate already exists', [
            'user_id'        => $user->id,
            'certificate_id' => $existing->id,
            'status'         => $existing->status,
            'context'        => $context,
        ]);

        ActivityLogger::log(
            'certificate.duplicate_prevented',
            $existing,
            [],
            null,
            null,
            ['user_id' => $user->id, 'context' => $context],
        );

        return AutomationResult::duplicate($existing);
    }

    protected function logResult(string $outcome, User $user, array $context): void
    {
        Log::info('[CertificateAutomation] ' . $outcome, array_merge(['user_id' => $user->id], $context));
    }
}
