<?php

namespace App\Http\Controllers\Student;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\CourseMaterial;
use App\Models\Registration;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaterialDownloadController extends Controller
{
    public function download(Request $request, CourseMaterial $material): StreamedResponse
    {
        abort_if(! $material->file_path, 404, 'This material has no downloadable file.');

        $user = $request->user();

        // Hidden materials are invisible to students/instructors even when enrolled.
        // Admins bypass this so they can verify material content before publishing.
        if (! $material->is_visible && ! $user->isAdmin()) {
            abort(403, 'This material is not currently available.');
        }

        abort_unless($this->canAccess($user, $material), 403, 'You are not enrolled in this course.');

        [$disk, $path] = $this->resolveDisk($material->file_path);

        abort_if($disk === null, 404, 'File not found.');

        $filename = basename($path);

        AuditLogService::logDownload($material, $filename, $request);

        return Storage::disk($disk)->download($path, $filename);
    }

    private function canAccess(\App\Models\User $user, CourseMaterial $material): bool
    {
        if (in_array($user->role, ['admin', 'super_admin', 'department_manager'], true)) {
            return true;
        }

        if ($user->isTeacher()) {
            $profile = $user->instructorProfile;
            if ($profile && $material->course_id) {
                return \App\Models\Course::where('id', $material->course_id)
                    ->where('instructor_id', $profile->id)
                    ->exists();
            }
        }

        return Registration::where('user_id', $user->id)
            ->where('course_id', $material->course_id)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->exists();
    }

    private function resolveDisk(string $path): array
    {
        if (Storage::disk('local')->exists($path)) {
            return ['local', $path];
        }

        if (Storage::disk('public')->exists($path)) {
            return ['public', $path];
        }

        return [null, $path];
    }
}
