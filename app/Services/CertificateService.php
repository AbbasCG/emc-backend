<?php

namespace App\Services;

use App\Events\Certificate\CertificateIssued;
use App\Events\Certificate\CertificateRevoked;
use App\Jobs\GenerateCertificatePdfJob;
use App\Models\Certificate;
use App\Models\CertificateGenerationBatch;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CertificateService
{
    public function issue(Certificate $certificate, User $approver): Certificate
    {
        $certificate->update([
            'status'      => 'issued',
            'issued_at'   => now(),
            'approved_by' => $approver->id,
        ]);

        ActivityLogger::log('certificate.issued', $certificate, [], request());
        event(new CertificateIssued($certificate->fresh()));

        // Notify the student.
        $certificate->loadMissing('user', 'course');
        $student = $certificate->user;
        if ($student) {
            NotificationService::certificateIssued(
                studentUserId:    $student->id,
                certificateId:    $certificate->id,
                courseTitle:      $certificate->course?->title ?? $certificate->title ?? 'الدورة',
                verificationCode: $certificate->verification_code ?? '',
            );
        }

        return $certificate->fresh();
    }

    public function approve(Certificate $certificate, User $approver): Certificate
    {
        $certificate->update([
            'status'      => 'approved',
            'approved_by' => $approver->id,
        ]);

        ActivityLogger::log('certificate.approved', $certificate, [], request());

        return $certificate->fresh();
    }

    public function revoke(Certificate $certificate, User $admin): Certificate
    {
        $certificate->update(['status' => 'revoked']);

        ActivityLogger::log('certificate.revoked', $certificate, [], request());
        event(new CertificateRevoked($certificate->fresh(), $certificate->revoke_reason));

        return $certificate->fresh();
    }

    public function generateVerificationCode(): string
    {
        do {
            $code = strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));
        } while (Certificate::where('verification_code', $code)->exists());

        return $code;
    }

    /**
     * @throws \LogicException always — synchronous PDF generation is disabled.
     *
     * Use GenerateCertificatePdfJob::dispatch($certificate) to queue PDF generation.
     * Synchronous generation blocks the HTTP thread, bypasses retry logic, and
     * circumvents the ShouldBeUnique deduplication guard on the job.
     *
     * @deprecated
     */
    public function generate(Certificate $certificate): never
    {
        throw new \LogicException(
            'CertificateService::generate() is disabled. '
            . 'Use GenerateCertificatePdfJob::dispatch($certificate) to queue PDF generation.'
        );
    }

    /**
     * Bulk issue certificates for multiple users. Creates DB records immediately,
     * then queues PDF generation per certificate.
     */
    public function issueBulk(array $userIds, array $params, User $issuedBy): CertificateGenerationBatch
    {
        $batch = CertificateGenerationBatch::create([
            'certificate_type' => $params['type'] ?? $params['certificate_type'] ?? 'course_completion',
            'related_type'     => $params['related_type'] ?? null,
            'related_id'       => $params['related_id'] ?? null,
            'template_id'      => $params['template_id'] ?? null,
            'status'           => 'pending',
            'total_recipients' => count($userIds),
            'created_by'       => $issuedBy->id,
            'notes'            => $params['notes'] ?? null,
        ]);

        $result = $this->createBulkCertificates(
            $userIds,
            array_merge($params, ['issued_by_id' => $issuedBy->id]),
            $batch
        );

        if ($result['created'] === 0) {
            $batch->update(['status' => 'failed']);
            throw ValidationException::withMessages([
                'user_ids' => ['لم يتم إنشاء أي شهادة — قد تكون مكررة أو غير صالحة.'],
            ]);
        }

        $batch->update([
            'status'           => 'processing',
            'total_recipients' => $result['created'],
            'failed_count'     => $result['skipped'],
        ]);

        foreach ($result['certificates'] as $cert) {
            GenerateCertificatePdfJob::dispatch($cert, $batch->id);
        }

        return $batch->fresh();
    }

    /**
     * Create certificate rows for a batch. Safe to call from queued BulkCertificateIssueJob
     * when records were not yet persisted (legacy queued jobs).
     *
     * @return array{created: int, skipped: int, certificates: Certificate[]}
     */
    public function createBulkCertificates(array $userIds, array $params, CertificateGenerationBatch $batch): array
    {
        $existingForBatch = $batch->certificates()->count();
        if ($existingForBatch > 0) {
            $certs = $batch->certificates()->where('status', 'pending_generation')->get();

            return [
                'created'      => $existingForBatch,
                'skipped'      => 0,
                'certificates' => $certs->all(),
            ];
        }

        $issuedById  = $params['issued_by_id'] ?? null;
        $type        = $params['type'] ?? $params['certificate_type'] ?? 'course_completion';
        $templateId  = $params['template_id'] ?? null;

        // Auto-assign the default designer template if none provided
        if (! $templateId) {
            $defaultTemplate = \App\Models\CertificateTemplate::where('is_default', true)
                ->where('is_active', true)
                ->first()
                ?? \App\Models\CertificateTemplate::where('designer_mode', true)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->first();
            $templateId = $defaultTemplate?->id;
        }
        $relatedType = $params['related_type'] ?? null;
        $relatedId   = $params['related_id'] ?? null;
        $override    = (bool) ($params['override'] ?? false);

        [$courseId, $workshopId, $trackId] = $this->resolveRelatedIds($relatedType, $relatedId);

        // Load all users in one query to avoid N+1 inside the loop.
        $userMap = User::whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        // Load existing non-revoked certificates for these users in one query so the
        // duplicate check below doesn't fire an individual query per user.
        $existingUserIds = ! $override
            ? Certificate::where('certificate_type', $type)
                ->whereIn('user_id', $userIds)
                ->where('status', '!=', 'revoked')
                ->when($relatedType, fn ($q) => $q->where('related_type', $relatedType))
                ->when($relatedId,   fn ($q) => $q->where('related_id',   $relatedId))
                ->pluck('user_id')
                ->flip()   // O(1) lookup
            : collect();

        $created      = 0;
        $skipped      = 0;
        $certificates = [];

        foreach ($userIds as $userId) {
            $user = $userMap->get($userId);
            if (! $user) {
                $skipped++;
                continue;
            }

            if (! $override && $existingUserIds->has($userId)) {
                $skipped++;
                continue;
            }

            try {
                $cert = Certificate::create([
                    'user_id'                 => $userId,
                    'course_id'               => $courseId,
                    'workshop_id'             => $workshopId,
                    'track_id'                => $trackId,
                    'certificate_type'        => $type,
                    'certificate_template_id' => $templateId,
                    'title'                   => $params['title'] ?? 'شهادة إتمام',
                    'recipient_name'          => $user->name,
                    'recipient_email'         => $user->email,
                    'status'                  => 'pending_generation',
                    'issued_by'               => $issuedById,
                    'batch_id'                => $batch->id,
                    'related_type'            => $relatedType,
                    'related_id'              => $relatedId,
                    'metadata'                => $params['metadata'] ?? null,
                ]);

                $certificates[] = $cert;
                $created++;
            } catch (\Throwable $e) {
                report($e);
                $skipped++;
            }
        }

        return [
            'created'      => $created,
            'skipped'      => $skipped,
            'certificates' => $certificates,
        ];
    }

    public function hasActiveDuplicate(int $userId, string $type, ?string $relatedType, ?int $relatedId): bool
    {
        return Certificate::query()
            ->where('user_id', $userId)
            ->where('certificate_type', $type)
            ->when($relatedType, fn ($q) => $q->where('related_type', $relatedType))
            ->when($relatedId, fn ($q) => $q->where('related_id', $relatedId))
            ->where('status', '!=', 'revoked')
            ->exists();
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    private function resolveRelatedIds(?string $relatedType, ?int $relatedId): array
    {
        $courseId   = null;
        $workshopId = null;
        $trackId    = null;

        if (in_array($relatedType, ['course', 'App\\Models\\Course'], true)) {
            $courseId = $relatedId;
        } elseif (in_array($relatedType, ['workshop', 'App\\Models\\Workshop'], true)) {
            $workshopId = $relatedId;
        } elseif (in_array($relatedType, ['learning_path', 'track', 'App\\Models\\Track', 'App\\Models\\LearningPath'], true)) {
            $trackId = $relatedId;
        }

        return [$courseId, $workshopId, $trackId];
    }

    /**
     * Generate a sequential certificate code.
     * Format: {prefix}-{year}-{padded_number}   e.g. EMC-CR-2026-00001
     */
    public function generateCode(string $prefix = 'EMC-CR'): string
    {
        $year = now()->year;

        $last = Certificate::withTrashed()
            ->where('certificate_code', 'like', "{$prefix}-{$year}-%")
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($last && preg_match('/(\d+)$/', $last->certificate_code, $m)) {
            $next = (int) $m[1] + 1;
        } else {
            $next = 1;
        }

        return sprintf('%s-%d-%05d', $prefix, $year, $next);
    }
}
