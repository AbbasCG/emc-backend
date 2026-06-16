<?php

namespace App\Http\Controllers;

use App\Models\AssignmentSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionDownloadController extends Controller
{
    /**
     * GET /api/submissions/{submission}/download
     *
     * Streams an assignment submission file through an authorized endpoint:
     * - the submitting student may download their own submission
     * - the instructor teaching the submission's course may download it
     * - admin/super_admin may download any submission
     *
     * Never expose the raw storage path directly to clients.
     */
    public function download(Request $request, AssignmentSubmission $submission): StreamedResponse
    {
        $user = $request->user();

        abort_unless($this->canAccess($user, $submission), 403, 'You are not authorized to access this submission.');

        abort_if(! $submission->file_path, 404, 'This submission has no file.');

        [$disk, $path] = $this->resolveDisk($submission->file_path);

        abort_if($disk === null, 404, 'File not found.');

        return Storage::disk($disk)->download($path, basename($path));
    }

    private function canAccess($user, AssignmentSubmission $submission): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        if ($submission->user_id === $user->id) {
            return true;
        }

        if ($user->isTeacher()) {
            $submission->loadMissing('assignment.course.instructor', 'assignment.workshop.course.instructor');

            $assignment = $submission->assignment;
            $course     = $assignment?->course ?? $assignment?->workshop?->course;
            $profile    = $user->instructorProfile;

            return $profile && $course && $course->instructor_id === $profile->id;
        }

        return false;
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
