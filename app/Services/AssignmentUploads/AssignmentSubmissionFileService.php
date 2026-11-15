<?php

namespace App\Services\AssignmentUploads;

use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Stores verified assignment attachments on the PRIVATE disk and exposes them to the API resources.
 *
 * Contract used by StudentAssignmentController::submit():
 *   1. every file was already verified (UploadedFileInspector) before anything is written;
 *   2. {@see store()} writes the files (and removes the ones it wrote if a later write fails);
 *   3. the caller replaces the DB rows inside its transaction ({@see replaceCurrentSet()}) and, if that
 *      transaction fails, calls {@see discard()} so no orphan files are left behind.
 *
 * Physical files are never deleted for superseded attempts: attempt history references them.
 */
class AssignmentSubmissionFileService
{
    public function __construct(private readonly AssignmentUploadPolicy $policy)
    {
    }

    public function disk(): string
    {
        return (string) config('assignment_uploads.disk', 'private');
    }

    /**
     * Internal, never user-influenced name: <directory>/<assignment_id>/<user_id>/<uuid>.<ext>.
     * Only integers and a server-side uuid + allow-listed extension enter the path.
     */
    public function newStoragePath(int $assignmentId, int $userId, string $extension): string
    {
        $extension = $extension === 'jpeg' ? 'jpg' : strtolower($extension);
        if (! in_array($extension, $this->policy->allExtensions(), true) && $extension !== 'jpg') {
            throw new RuntimeException('Refusing to store an extension that is not allow-listed.');
        }

        return trim((string) config('assignment_uploads.directory', 'assignment-submissions'), '/')
            .'/'.$assignmentId.'/'.$userId.'/'.Str::uuid()->toString().'.'.$extension;
    }

    /**
     * Writes the files, skipping content that is already part of the request (same sha256) and reusing the physical
     * file of identical content already in the current set (`$reusable`: sha256 => stored_path).
     *
     * @param  list<InspectedUpload>  $uploads
     * @param  array<string,string>   $reusable
     * @return list<array{upload:InspectedUpload, stored_path:string, newly_stored:bool}>
     *
     * @throws RuntimeException when a file cannot be written (files written by this call are removed first)
     */
    public function store(array $uploads, int $assignmentId, int $userId, array $reusable = []): array
    {
        $records = [];
        $seen = [];

        try {
            foreach ($uploads as $upload) {
                if (isset($seen[$upload->sha256])) {
                    continue; // identical content twice in one request: keep the first
                }
                $seen[$upload->sha256] = true;

                if (isset($reusable[$upload->sha256]) && Storage::disk($this->disk())->exists($reusable[$upload->sha256])) {
                    $records[] = ['upload' => $upload, 'stored_path' => $reusable[$upload->sha256], 'newly_stored' => false];

                    continue;
                }

                $path = $this->newStoragePath($assignmentId, $userId, $upload->extension);
                $written = Storage::disk($this->disk())->putFileAs(dirname($path), $upload->file, basename($path));

                if ($written === false) {
                    throw new RuntimeException('Failed to write an assignment attachment to the private disk.');
                }

                $records[] = ['upload' => $upload, 'stored_path' => $path, 'newly_stored' => true];
            }
        } catch (Throwable $e) {
            $this->discard($records);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        return $records;
    }

    /** Removes the files that THIS request newly wrote (never reused, pre-existing ones). */
    public function discard(array $records): void
    {
        foreach ($records as $record) {
            if (! ($record['newly_stored'] ?? false)) {
                continue;
            }
            try {
                Storage::disk($this->disk())->delete($record['stored_path']);
            } catch (Throwable $e) {
                Log::warning('Could not remove an orphaned assignment attachment after a failed submission.', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Makes `$records` the submission's CURRENT attachment set. Must run inside the caller's transaction, AFTER the
     * previous set was copied into history (AssignmentSubmissionHistoryService::snapshot()).
     *
     * @param list<array{upload:InspectedUpload, stored_path:string, newly_stored:bool}> $records
     */
    public function replaceCurrentSet(AssignmentSubmission $submission, array $records): void
    {
        AssignmentSubmissionFile::query()
            ->where('submission_id', $submission->id)
            ->where('attempt_number', 0)
            ->delete();

        foreach ($records as $record) {
            $u = $record['upload'];
            AssignmentSubmissionFile::create([
                'submission_id'  => $submission->id,
                'attempt_number' => 0,
                'original_name'  => $u->displayName,
                'stored_path'    => $record['stored_path'],
                'mime'           => $u->mime,
                'extension'      => $u->extension === 'jpeg' ? 'jpg' : $u->extension,
                'size_bytes'     => $u->size,
                'sha256'         => $u->sha256,
            ]);
        }

        $submission->unsetRelation('currentFiles');
    }

    /** sha256 => stored_path of the submission's current set (for idempotent retries). @return array<string,string> */
    public function currentSetIndex(?AssignmentSubmission $submission): array
    {
        if (! $submission) {
            return [];
        }

        return AssignmentSubmissionFile::query()
            ->where('submission_id', $submission->id)
            ->where('attempt_number', 0)
            ->pluck('stored_path', 'sha256')
            ->all();
    }

    public function hasCurrentFiles(?AssignmentSubmission $submission): bool
    {
        if (! $submission) {
            return false;
        }

        return filled($submission->file_path)
            || AssignmentSubmissionFile::query()->where('submission_id', $submission->id)->where('attempt_number', 0)->exists();
    }

    // ── API presentation ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * `attachments` payload for a submission's CURRENT set. A submission that predates multi-file uploads (only
     * `file_path`) is presented as one synthetic attachment with the stable id "legacy"; no internal path is exposed.
     *
     * @return list<array{id:int|string, name:string, size:int|null, mime:string, download_url:string}>
     */
    public function attachmentsFor(AssignmentSubmission $submission): array
    {
        /** @var Collection<int,AssignmentSubmissionFile> $files */
        $files = $submission->relationLoaded('currentFiles') ? $submission->currentFiles : $submission->currentFiles()->get();

        if ($files->isNotEmpty()) {
            return $files->map(fn (AssignmentSubmissionFile $f) => $this->presentFile($submission, $f))->values()->all();
        }

        if (filled($submission->file_path)) {
            $name = basename((string) $submission->file_path);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            return [[
                'id'           => 'legacy',
                'name'         => $name,
                'size'         => null,
                'mime'         => $this->policy->canonicalMime($ext),
                'download_url' => route('submissions.download', $submission->id),
            ]];
        }

        return [];
    }

    /** @return array{id:int,name:string,size:int,mime:string,download_url:string} */
    public function presentFile(AssignmentSubmission $submission, AssignmentSubmissionFile $file): array
    {
        return [
            'id'           => $file->id,
            'name'         => $file->original_name,
            'size'         => (int) $file->size_bytes,
            'mime'         => $file->mime,
            'download_url' => route('submissions.files.download', ['submission' => $submission->id, 'file' => $file->id]),
        ];
    }
}
