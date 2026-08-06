<?php

namespace App\Console\Commands;

use App\Jobs\GenerateCertificatePdfJob;
use App\Models\Certificate;
use Illuminate\Console\Command;

class RepairMissingCertificatePdfsCommand extends Command
{
    protected $signature = 'certificates:repair-missing-pdfs
                            {--dry-run : Show what would be processed without doing it}
                            {--limit=50 : Maximum certificates to process}
                            {--force : Regenerate even if PDF path is set but file is missing}';

    protected $description = 'Find issued certificates missing PDF files and queue regeneration.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = (int)  ($this->option('limit') ?? 50);

        $query = Certificate::where('status', 'issued')
            ->where(function ($q) {
                $q->whereNull('pdf_path')
                  ->orWhere('pdf_generation_status', '!=', 'ready');
            })
            ->limit($limit);

        if ($this->option('force')) {
            // Also include certs whose stored file no longer exists on disk
            $query = Certificate::where('status', 'issued')->limit($limit);
        }

        $certs = $query->get();

        if ($certs->isEmpty()) {
            $this->info('No certificates need repair.');
            return self::SUCCESS;
        }

        $toRepair = $this->option('force')
            ? $certs->filter(fn ($c) => ! $c->pdf_path || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($c->pdf_path ?? ''))
            : $certs;

        $this->info("Found {$toRepair->count()} certificate(s) needing PDF repair.");

        if ($dryRun) {
            $this->table(
                ['ID', 'Code', 'Recipient', 'Status', 'PDF Status'],
                $toRepair->map(fn ($c) => [
                    $c->id, $c->certificate_code ?? '—', $c->recipient_name ?? '—', $c->status, $c->pdf_generation_status ?? '—',
                ])->toArray(),
            );
            $this->warn('[dry-run] No jobs dispatched.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($toRepair as $cert) {
            GenerateCertificatePdfJob::dispatch($cert, null, true)->onQueue(config('certificates.queue', 'certificates'));
            $dispatched++;
            $this->line("  → Queued: #{$cert->id} {$cert->certificate_code}");
        }

        $this->info("Dispatched {$dispatched} PDF generation job(s).");
        return self::SUCCESS;
    }
}
