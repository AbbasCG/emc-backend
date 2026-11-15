<?php

namespace App\Http\Controllers;

use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionFile;
use App\Services\AssignmentUploads\AssignmentSubmissionFileService;
use App\Support\SubmissionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionFileDownloadController extends Controller
{
    private const SAFE_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control'          => 'private, no-store',
    ];

    /**
     * GET /api/submissions/{submission}/files/{file}/download
     *
     * Same access rule as /submissions/{id}/download (SubmissionAccess): the submitting student, the instructor of
     * that submission's course, admin / super_admin. Authorization is decided BEFORE the file id is looked up, so a
     * caller without access learns nothing about which file ids exist; the file must belong to THIS submission, so a
     * valid id from another submission is a 404, never a cross-submission read (no IDOR).
     *
     * The response is always an attachment (never rendered inline), is `nosniff`, and anything that is not an image or
     * a PDF is sent as application/octet-stream so a browser can never interpret a stored file as active content.
     */
    public function download(Request $request, AssignmentSubmission $submission, string $file): StreamedResponse|JsonResponse
    {
        if (! SubmissionAccess::canAccess($request->user(), $submission)) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بالوصول إلى هذا الملف.'], 403, self::SAFE_HEADERS);
        }

        $row = ctype_digit($file)
            ? AssignmentSubmissionFile::query()->where('submission_id', $submission->id)->whereKey((int) $file)->first()
            : null;

        $service = app(AssignmentSubmissionFileService::class);
        $disk = $service->disk();

        if (! $row || str_contains($row->stored_path, '..') || ! Storage::disk($disk)->exists($row->stored_path)) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود.'], 404, self::SAFE_HEADERS);
        }

        $inlineSafe = (str_starts_with($row->mime, 'image/') && ! str_contains($row->mime, 'svg')) || $row->mime === 'application/pdf';

        return Storage::disk($disk)->download($row->stored_path, $row->original_name, [
            'Content-Type' => $inlineSafe ? $row->mime : 'application/octet-stream',
        ] + self::SAFE_HEADERS);
    }
}
