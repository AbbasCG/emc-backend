<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupOrphanCertificatePdfsCommand extends Command
{
    protected $signature = 'certificates:cleanup-orphan-pdfs
                            {--dry-run : Show what would be deleted without deleting}
                            {--force : Actually delete orphan files}';

    protected $description = 'Find PDF files in storage that no longer belong to any certificate and remove them.';

    /** Base storage path where certificate PDFs live. */
    private const PDF_BASE = 'certificates';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');

        if (! $dryRun && ! $force) {
            $this->error('Pass --dry-run to preview or --force to actually delete. No action taken.');
            return self::FAILURE;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists(self::PDF_BASE)) {
            $this->info('Certificate PDF directory does not exist — nothing to clean.');
            return self::SUCCESS;
        }

        $files = $disk->allFiles(self::PDF_BASE);
        $pdfFiles = array_filter($files, fn ($f) => str_ends_with($f, '.pdf') || str_ends_with($f, '.html'));

        if (empty($pdfFiles)) {
            $this->info('No PDF files found in certificate storage.');
            return self::SUCCESS;
        }

        // Build lookup of known pdf_paths
        $knownPaths = Certificate::whereNotNull('pdf_path')->pluck('pdf_path')->flip();

        $orphans = [];
        foreach ($pdfFiles as $file) {
            if (! $knownPaths->has($file)) {
                $orphans[] = $file;
            }
        }

        if (empty($orphans)) {
            $this->info('No orphan PDF files found.');
            return self::SUCCESS;
        }

        $this->info('Found ' . count($orphans) . ' orphan file(s).');

        if ($dryRun) {
            foreach ($orphans as $path) {
                $size = $disk->size($path);
                $this->line("  [orphan] {$path} (" . number_format($size / 1024, 1) . ' KB)');
            }
            $this->warn('[dry-run] No files deleted.');
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($orphans as $path) {
            try {
                $disk->delete($path);
                $deleted++;
                $this->line("  Deleted: {$path}");
            } catch (\Throwable $e) {
                $this->error("  Failed to delete {$path}: {$e->getMessage()}");
            }
        }

        $this->info("Deleted {$deleted} orphan file(s).");
        return self::SUCCESS;
    }
}
