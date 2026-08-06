<?php

namespace App\Console\Commands;

use App\Jobs\GenerateCertificatePdfJob;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;

class RetryFailedCertificatesCommand extends Command
{
    protected $signature = 'certificates:retry-failed
                            {--dry-run : Show what would be retried without dispatching jobs}
                            {--limit=25 : Maximum certificates to retry}
                            {--batch= : Retry only failures belonging to this batch ID}
                            {--max-retries= : Skip certificates that have already been command-retried this many times (default: certificates.max_retry_count config)}';

    protected $description = 'Retry PDF generation for failed certificates.';

    public function handle(): int
    {
        $dryRun     = (bool) $this->option('dry-run');
        $limit      = (int)  ($this->option('limit') ?? 25);
        $batchId    = $this->option('batch') ? (int) $this->option('batch') : null;
        $maxRetries = (int)  ($this->option('max-retries') ?? config('certificates.max_retry_count', 5));

        $certs = Certificate::where('status', 'generation_failed')
            ->where('pdf_command_retry_count', '<', $maxRetries)
            ->when($batchId, fn ($q) => $q->where('batch_id', $batchId))
            ->limit($limit)
            ->get();

        $permanentlyFailed = Certificate::where('status', 'generation_failed')
            ->where('pdf_command_retry_count', '>=', $maxRetries)
            ->when($batchId, fn ($q) => $q->where('batch_id', $batchId))
            ->count();

        if ($permanentlyFailed > 0) {
            $this->warn("⚠ {$permanentlyFailed} certificate(s) exceeded the retry limit ({$maxRetries}) and will NOT be retried.");
            $this->warn('  Review pdf_last_error on these records and fix the underlying cause before force-retrying.');
        }

        if ($certs->isEmpty()) {
            $this->info('No failed certificates found.');
            return self::SUCCESS;
        }

        $this->info("Found {$certs->count()} failed certificate(s).");

        if ($dryRun) {
            $this->table(
                ['ID', 'Code', 'Recipient', 'Last Error'],
                $certs->map(fn ($c) => [
                    $c->id,
                    $c->certificate_code ?? '—',
                    $c->recipient_name ?? '—',
                    mb_substr($c->pdf_last_error ?? '—', 0, 60),
                ])->toArray(),
            );
            $this->warn('[dry-run] No jobs dispatched.');
            return self::SUCCESS;
        }

        $retried = 0;
        foreach ($certs as $cert) {
            // Increment command retry counter and reset generation status
            $cert->update([
                'status'                    => 'pending_generation',
                'pdf_generation_status'     => 'pending',
                'pdf_last_error'            => null,
                'pdf_command_retry_count'   => $cert->pdf_command_retry_count + 1,
            ]);

            GenerateCertificatePdfJob::dispatch($cert, $cert->batch_id, false)
                ->onQueue(config('certificates.queue', 'certificates'));

            ActivityLogger::log('certificate.retry_queued', $cert, [], null, null, [
                'triggered_by'  => 'artisan:retry-failed',
                'batch_id'      => $batchId,
                'command_retry' => $cert->pdf_command_retry_count,
            ]);

            $retried++;
            $this->line("  → Retrying: #{$cert->id} {$cert->certificate_code} (attempt {$cert->pdf_command_retry_count}/{$maxRetries})");
        }

        $this->info("Queued {$retried} retry job(s).");
        return self::SUCCESS;
    }
}
