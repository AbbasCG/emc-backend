<?php

namespace App\Http\Controllers;

use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionFile;
use App\Services\AssignmentUploads\AssignmentUploadPolicy;
use App\Support\SubmissionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionDownloadController extends Controller
{
    /** Hardening headers on EVERY response of these endpoints (success and error alike). */
    private const SAFE_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control'          => 'private, no-store',
    ];

    /**
     * GET /api/submissions/{submission}/download
     *
     * Streams an assignment submission file through an authorized endpoint:
     * - the submitting student may download their own submission
     * - the instructor teaching the submission's course may download it
     * - admin/super_admin may download any submission
     *
     * Never expose the raw storage path directly to clients. Always an attachment, never rendered by the browser.
     */
    public function download(Request $request, AssignmentSubmission $submission): StreamedResponse
    {
        $user = $request->user();

        abort_unless($this->canAccess($user, $submission), 403, 'غير مصرح لك بالوصول إلى هذا التسليم.');

        abort_if(! $submission->file_path, 404, 'لا يوجد ملف لهذا التسليم.');

        [$disk, $path] = $this->resolveDisk($submission->file_path);

        abort_if($disk === null, 404, 'الملف غير موجود.');

        [$name, $mime] = $this->describe($submission, $path);

        return Storage::disk($disk)->download($path, $name, [
            'Content-Type' => $this->safeMime($mime),
        ] + self::SAFE_HEADERS);
    }

    /**
     * GET /api/instructor/submissions/{submission}/file
     *
     * Instructor-facing file endpoint. Returns JSON errors (never redirects). Streams inline ONLY for images and PDFs
     * (decided from the VERIFIED type, never from sniffing the stored bytes); everything else, and every request with
     * ?download=1, is an attachment sent as application/octet-stream.
     */
    public function instructorFile(Request $request, AssignmentSubmission $submission): StreamedResponse|JsonResponse
    {
        $user = Auth::guard('sanctum')->user();

        if (! $user) {
            return $this->json(['message' => 'يجب تسجيل الدخول أولاً.'], 401);
        }

        if (! $this->canAccess($user, $submission)) {
            return $this->json(['message' => 'غير مصرح لك بالوصول إلى هذا التسليم.'], 403);
        }

        if (! $submission->file_path) {
            return $this->json(['message' => 'لا يوجد ملف مرفق.'], 404);
        }

        [$disk, $path] = $this->resolveDisk($submission->file_path);

        if ($disk === null) {
            return $this->json(['message' => 'الملف غير موجود.'], 404);
        }

        [$name, $mime] = $this->describe($submission, $path);
        $safeMime = $this->safeMime($mime);
        $headers  = ['Content-Type' => $safeMime] + self::SAFE_HEADERS;

        if ($request->boolean('download') || $safeMime === 'application/octet-stream') {
            return Storage::disk($disk)->download($path, $name, $headers);
        }

        // Inline images get a locked-down CSP as defence in depth. It is deliberately NOT applied to PDFs: `sandbox`
        // (and a restrictive default-src) stops browsers' built-in PDF viewers from rendering.
        if (str_starts_with($safeMime, 'image/')) {
            $headers['Content-Security-Policy'] = "default-src 'none'; sandbox";
        }

        return Storage::disk($disk)->response($path, $name, $headers, 'inline');
    }

    private function canAccess($user, AssignmentSubmission $submission): bool
    {
        return SubmissionAccess::canAccess($user, $submission);
    }

    private function json(array $payload, int $status): JsonResponse
    {
        return response()->json($payload, $status, self::SAFE_HEADERS);
    }

    /**
     * Display name and VERIFIED mime for a stored file. A multi-file row (written by the new upload pipeline, which
     * mirrors its first file into `file_path`) carries both; a legacy file only has its path, so the type comes from a
     * conservative extension map (unknown extension => octet-stream), never from sniffing the stored content.
     *
     * @return array{0:string,1:string}
     */
    private function describe(AssignmentSubmission $submission, string $path): array
    {
        $row = AssignmentSubmissionFile::query()
            ->where('submission_id', $submission->id)
            ->where('stored_path', $path)
            ->orderBy('attempt_number')
            ->first();

        if ($row) {
            return [$row->original_name, $row->mime];
        }

        $name = basename($path);

        return [$name, app(AssignmentUploadPolicy::class)->canonicalMime(pathinfo($name, PATHINFO_EXTENSION))];
    }

    /** Only images (never SVG) and PDFs keep their type; anything a browser could interpret as active content becomes opaque bytes. */
    private function safeMime(string $mime): string
    {
        $mime = strtolower($mime);

        if ($mime === 'application/pdf' || (str_starts_with($mime, 'image/') && ! str_contains($mime, 'svg'))) {
            return $mime;
        }

        return 'application/octet-stream';
    }

    /**
     * Backward-compatible disk resolution: new submissions are stored on the
     * private disk, but submissions uploaded before this fix may still be on
     * `public`.
     */
    private function resolveDisk(string $path): array
    {
        if (Storage::disk('private')->exists($path)) {
            return ['private', $path];
        }

        if (Storage::disk('public')->exists($path)) {
            return ['public', $path];
        }

        return [null, $path];
    }
}
