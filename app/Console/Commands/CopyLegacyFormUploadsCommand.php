<?php

namespace App\Console\Commands;

use App\Models\FormAnswer;
use App\Services\Forms\FormAttachmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Form attachments used to be written to the PUBLIC disk, which anyone with the path could open. New ones go to the
 * private disk. This copies the old ones across so the secure download endpoint serves them from private storage.
 *
 * Safe by construction:
 *  - dry run unless --apply is given;
 *  - it only COPIES, to the same relative path form_answers.file_path already holds, so no database row changes;
 *  - it never overwrites: a file that already exists on the private disk is left exactly as it is;
 *  - it never deletes or edits anything on the public disk, and never deletes a pre-existing private file;
 *  - each copy is verified (same size) and, if verification fails, only the copy THIS run just wrote is removed;
 *  - paths that are not plain `form-uploads/...` paths are skipped, whatever the database says;
 *  - the public originals are listed at the end: removing them (which is what actually closes the old public URLs)
 *    is a deliberate, separate step for an administrator, after checking the copies.
 *
 * Exit code is non-zero if any file could not be copied or verified, so a deployment script can stop.
 */
class CopyLegacyFormUploadsCommand extends Command
{
    protected $signature = 'forms:copy-legacy-uploads {--apply : Actually copy the files (default is a dry run)}';

    protected $description = 'Copy legacy public-disk form attachments to the private disk (never deletes or overwrites anything)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $private = Storage::disk(FormAttachmentService::DISK);
        $public = Storage::disk(FormAttachmentService::LEGACY_DISK);

        $copied = $already = $missing = $unsafe = $failed = 0;
        $originals = [];

        FormAnswer::query()->whereNotNull('file_path')->chunkById(200, function ($answers) use ($apply, $private, $public, &$copied, &$already, &$missing, &$unsafe, &$failed, &$originals) {
            foreach ($answers as $answer) {
                $path = (string) $answer->file_path;

                if (! FormAttachmentService::isSafePath($path)) {
                    $unsafe++;
                    $this->warn("answer {$answer->id}: skipped, not a form upload path");

                    continue;
                }

                if ($private->exists($path)) {
                    $already++;
                    if ($public->exists($path)) {
                        $originals[] = $path;
                    }

                    continue;
                }

                if (! $public->exists($path)) {
                    $missing++;
                    $this->warn("answer {$answer->id}: file not found on either disk ({$path})");

                    continue;
                }

                if ($apply && ! $this->copyVerified($public, $private, $path)) {
                    $failed++;
                    $this->error("answer {$answer->id}: copy failed or did not verify ({$path}); nothing was kept for it");

                    continue;
                }

                $copied++;
                $originals[] = $path;
            }
        });

        $verb = $apply ? 'copied' : 'would copy';
        $this->info("{$verb}: {$copied}; already private: {$already}; missing: {$missing}; skipped: {$unsafe}; failed: {$failed}");

        if ($originals !== []) {
            $this->line('');
            $this->line('Public originals still present (NOT removed — delete them only after verifying the private copies):');
            foreach ($originals as $path) {
                $this->line("  storage/app/public/{$path}");
            }
        }

        if (! $apply) {
            $this->comment('Dry run. Re-run with --apply to copy.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Copies one file and checks the copy; on any problem removes the partial copy it just made (never anything older). */
    private function copyVerified($public, $private, string $path): bool
    {
        $stream = null;

        try {
            $stream = $public->readStream($path);
            if (! is_resource($stream)) {
                return false;
            }
            $private->writeStream($path, $stream);

            if ($private->size($path) === $public->size($path)) {
                return true;
            }
        } catch (\Throwable $e) {
            report($e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Only reached on failure, and only after `exists()` said the private path was free before this run wrote it.
        try {
            $private->delete($path);
        } catch (\Throwable $e) {
            report($e);
        }

        return false;
    }
}
