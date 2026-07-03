<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditCertificateIntegrityCommand extends Command
{
    protected $signature = 'certificates:audit-integrity
                            {--dry-run : Report only — make no changes}
                            {--limit=500 : Max certificates to scan}';

    protected $description = 'Audit certificate data integrity: duplicates, missing PDFs, bad statuses, template gaps.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = (int)  ($this->option('limit') ?? 500);
        $issues = [];

        $this->info('Running certificate integrity audit...');

        // ── 1. Duplicate certificate codes ────────────────────────────────────
        $dupCodes = DB::table('certificates')
            ->whereNotNull('certificate_code')
            ->select('certificate_code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('certificate_code')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($dupCodes as $row) {
            $issues[] = ['Duplicate code', $row->certificate_code, "Appears {$row->cnt} times"];
        }

        // ── 2. Duplicate user+type+related combinations ───────────────────────
        $dupCombos = DB::table('certificates')
            ->select('user_id', 'certificate_type', 'related_type', 'related_id', DB::raw('COUNT(*) as cnt'))
            ->where('status', '!=', 'revoked')
            ->whereNotNull('related_id')
            ->groupBy('user_id', 'certificate_type', 'related_type', 'related_id')
            ->having('cnt', '>', 1)
            ->limit(50)
            ->get();

        foreach ($dupCombos as $row) {
            $issues[] = [
                'Duplicate user+context',
                "user:{$row->user_id} / {$row->certificate_type} / {$row->related_type}:{$row->related_id}",
                "Count: {$row->cnt}",
            ];
        }

        // ── 3. Issued certificates with no PDF ────────────────────────────────
        $missingPdf = Certificate::where('status', 'issued')
            ->where(function ($q) {
                $q->whereNull('pdf_path')
                  ->orWhere('pdf_generation_status', '!=', 'ready');
            })
            ->limit($limit)
            ->get();

        foreach ($missingPdf as $cert) {
            $issues[] = ['Issued without PDF', $cert->certificate_code ?? "ID:{$cert->id}", 'Run: certificates:repair-missing-pdfs'];
        }

        // ── 4. PDF file exists in DB but not on disk ──────────────────────────
        $withPdfPath = Certificate::whereNotNull('pdf_path')->limit($limit)->get();
        foreach ($withPdfPath as $cert) {
            if (! Storage::disk('local')->exists($cert->pdf_path)) {
                $issues[] = ['PDF file missing on disk', $cert->certificate_code ?? "ID:{$cert->id}", $cert->pdf_path];
            }
        }

        // ── 5. Missing template ───────────────────────────────────────────────
        $noTemplate = Certificate::whereNull('certificate_template_id')
            ->whereNotIn('status', ['revoked', 'rejected'])
            ->limit($limit)
            ->count();

        if ($noTemplate > 0) {
            $issues[] = ['No template assigned', "{$noTemplate} certificate(s)", 'Assign a default template'];
        }

        // ── 6. Invalid approval states ────────────────────────────────────────
        $badApproval = Certificate::where('status', 'issued')
            ->whereNotIn('approval_status', ['both_approved', 'admin_approved', 'program_manager_approved', null, ''])
            ->limit(50)
            ->count();

        if ($badApproval > 0) {
            $issues[] = ['Issued with bad approval_status', "{$badApproval} certificate(s)", 'Manual review needed'];
        }

        // ── 7. Stuck generating ───────────────────────────────────────────────
        $stuckMinutes    = (int) config('certificates.stuck_generation_minutes', 30);
        $stuckGenerating = Certificate::where('status', 'pending_generation')
            ->where('updated_at', '<', now()->subMinutes($stuckMinutes))
            ->limit(50)
            ->count();

        if ($stuckGenerating > 0) {
            $issues[] = ['Stuck in pending_generation > ' . $stuckMinutes . 'm', "{$stuckGenerating} certificate(s)", 'Run: certificates:retry-failed'];
        }

        // ── 8. Stuck batches ──────────────────────────────────────────────────
        $stuckHours   = (int) config('certificates.stuck_batch_hours', 2);
        $stuckBatches = \App\Models\CertificateGenerationBatch::where('status', 'processing')
            ->where('updated_at', '<', now()->subHours($stuckHours))
            ->count();

        if ($stuckBatches > 0) {
            $issues[] = ['Batch stuck in processing > ' . $stuckHours . 'h', "{$stuckBatches} batch(es)", 'Check queue workers; run certificates:retry-failed --batch=ID'];
        }

        // ── 9. Certificates exceeding command retry limit ─────────────────────
        $maxRetries        = (int) config('certificates.max_retry_count', 5);
        $permanentlyFailed = Certificate::where('status', 'generation_failed')
            ->where('pdf_command_retry_count', '>=', $maxRetries)
            ->count();

        if ($permanentlyFailed > 0) {
            $issues[] = ['Permanently failed (retry limit reached)', "{$permanentlyFailed} certificate(s)", 'Review pdf_last_error and fix the underlying cause'];
        }

        // ── Report ────────────────────────────────────────────────────────────
        if (empty($issues)) {
            $this->info('✓ No integrity issues found.');
            return self::SUCCESS;
        }

        $this->warn(count($issues) . ' issue(s) found:');
        $this->table(['Issue Type', 'Detail', 'Action'], $issues);

        if ($dryRun) {
            $this->warn('[dry-run] No changes made. Run without --dry-run to apply fixes where applicable.');
        }

        return self::FAILURE;
    }
}
