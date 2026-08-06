<?php

namespace App\Services\Certificate;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Services\CertificateGeneratorService;
use App\Services\CertificatePdfService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Single orchestrator for all certificate rendering.
 *
 * This is THE ONLY service that should orchestrate:
 *  - template resolution  (with caching)
 *  - context building (cfg + data)
 *  - HTML preview rendering
 *  - PDF generation (in-memory and on-disk)
 *  - download responses
 *
 * Controllers and jobs must call this service — never resolve templates or
 * build rendering context inline.
 */
class CertificateRenderService
{
    // Cache TTL and key for default template lookup
    private const TEMPLATE_CACHE_KEY = 'cert:default_template_id';

    public function __construct(
        private readonly CertificatePdfService       $pdfService,
        private readonly CertificateGeneratorService $generatorService,
    ) {}

    // ── Template resolution ───────────────────────────────────────────────────

    /**
     * THE single point of template resolution for the entire certificate system.
     *
     * Resolution order:
     *   1. Certificate's own assigned template (designer_mode + is_active)
     *   2. System default designer template   (is_default + is_active) — cached
     *   3. Any active designer template        (oldest first)
     *
     * If found via 2 or 3, auto-assigns to the certificate via saveQuietly().
     */
    public function resolveTemplate(Certificate $certificate): ?CertificateTemplate
    {
        $certificate->loadMissing('template');

        $template = $certificate->template;

        if ($template && $template->designer_mode && $template->is_active) {
            Log::debug('[Certificate] Template: own template', [
                'certificate_id' => $certificate->id,
                'template_id'    => $template->id,
            ]);
            return $template;
        }

        $template = $this->resolveDefaultTemplate();

        if ($template) {
            $certificate->certificate_template_id = $template->id;
            $certificate->saveQuietly();
            $certificate->setRelation('template', $template);

            Log::info('[Certificate] Template: auto-assigned default', [
                'certificate_id' => $certificate->id,
                'template_id'    => $template->id,
                'template_name'  => $template->name,
            ]);
        } else {
            Log::warning('[Certificate] Template: no designer template found — using fallback config', [
                'certificate_id' => $certificate->id,
            ]);
        }

        return $template;
    }

    /**
     * Return the system default template, using a short-lived cache to avoid
     * repeated DB queries on every download or batch job.
     *
     * Cache is invalidated by CertificateTemplateController on any mutation.
     */
    public function resolveDefaultTemplate(): ?CertificateTemplate
    {
        $ttl = (int) config('certificates.template_cache_ttl', 600);

        $templateId = Cache::remember(self::TEMPLATE_CACHE_KEY, $ttl, function () {
            $t = CertificateTemplate::where('is_default', true)
                ->where('is_active', true)
                ->first()
                ?? CertificateTemplate::where('designer_mode', true)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->first();

            return $t?->id;
        });

        if (! $templateId) {
            return null;
        }

        // Load full model; cache only stores the ID to avoid stale serialized objects
        return Cache::remember("cert:template:{$templateId}", $ttl, function () use ($templateId) {
            return CertificateTemplate::find($templateId);
        });
    }

    /**
     * Flush all template caches. Call after any template mutation.
     */
    public static function flushTemplateCache(?int $templateId = null): void
    {
        Cache::forget(self::TEMPLATE_CACHE_KEY);

        if ($templateId) {
            Cache::forget("cert:template:{$templateId}");
        }
    }

    // ── Context building ──────────────────────────────────────────────────────

    /**
     * Build the full rendering context (cfg + data) for a certificate.
     * Returns ['cfg' => [...], 'data' => [...]].
     */
    public function buildRenderContext(Certificate $certificate, bool $forPdf = false): array
    {
        $certificate->loadMissing(['template', 'user', 'course.learningPath', 'workshop', 'track']);

        $template = $this->resolveTemplate($certificate);

        $cfg = $template
            ? $this->generatorService->buildTemplateConfig($template, forPdf: $forPdf)
            : $this->fallbackConfig($forPdf);

        $data = $this->generatorService->buildTemplateData($certificate);

        return compact('cfg', 'data');
    }

    // ── HTML rendering (preview) ──────────────────────────────────────────────

    /**
     * Render certificate as HTML string.
     * Intended for admin preview / designer preview — NOT for download.
     */
    public function renderHtml(Certificate $certificate): string
    {
        ['cfg' => $cfg, 'data' => $data] = $this->buildRenderContext($certificate, forPdf: false);

        $html = $this->pdfService->renderBlade($cfg, $data);

        Log::info('[Certificate] Rendered HTML for preview', [
            'certificate_id' => $certificate->id,
        ]);

        return $html;
    }

    // ── PDF rendering (in-memory) ─────────────────────────────────────────────

    /**
     * Render certificate as PDF bytes (without writing to disk).
     * Used for streaming downloads — returns raw binary PDF content.
     */
    public function renderPdf(Certificate $certificate): string
    {
        ['cfg' => $cfg, 'data' => $data] = $this->buildRenderContext($certificate, forPdf: true);

        $pdfBytes = $this->pdfService->convertToPdf($cfg, $data);

        Log::info('[Certificate] Rendered PDF in-memory', [
            'certificate_id'   => $certificate->id,
            'template_id'      => $certificate->certificate_template_id,
            'certificate_code' => $certificate->certificate_code,
            'size_bytes'       => strlen($pdfBytes),
        ]);

        return $pdfBytes;
    }

    // ── PDF storage ───────────────────────────────────────────────────────────

    /**
     * Generate a real PDF, store it on disk, update pdf_path on the certificate.
     *
     * Existing PDF at the old path is deleted before writing the new file,
     * preventing orphaned files on regeneration.
     *
     * @return string  The stored path under storage/app/private/.
     */
    public function storePdf(Certificate $certificate): string
    {
        $certificate->loadMissing(['template', 'user', 'course.learningPath', 'workshop', 'track']);

        Log::info('[Certificate] Storing PDF', [
            'certificate_id'   => $certificate->id,
            'certificate_code' => $certificate->certificate_code,
            'template_id'      => $certificate->certificate_template_id,
        ]);

        ['cfg' => $cfg, 'data' => $data] = $this->buildRenderContext($certificate, forPdf: true);

        $pdfBytes = $this->pdfService->convertToPdf($cfg, $data);

        $year         = now()->year;
        $code         = $certificate->certificate_code ?? $certificate->verification_code ?? $certificate->id;
        $relativePath = "certificates/{$year}/official/{$code}.pdf";
        $storagePath  = "private/{$relativePath}";

        // Remove old file if path is changing to avoid orphaned PDFs
        $oldPath = $certificate->pdf_path;
        if ($oldPath && $oldPath !== $storagePath && Storage::disk('local')->exists($oldPath)) {
            Storage::disk('local')->delete($oldPath);

            Log::info('[Certificate] Deleted old PDF before replacement', [
                'certificate_id' => $certificate->id,
                'old_path'       => $oldPath,
            ]);
        }

        Storage::disk('local')->put($storagePath, $pdfBytes);
        $certificate->update(['pdf_path' => $storagePath]);

        Log::info('[Certificate] PDF stored on disk', [
            'certificate_id' => $certificate->id,
            'path'           => $storagePath,
            'size_bytes'     => strlen($pdfBytes),
        ]);

        return $storagePath;
    }

    // ── Download response ─────────────────────────────────────────────────────

    /**
     * Build and return an HTTP response that streams the certificate as a real PDF.
     *
     * Always re-renders on-the-fly so the latest template is used.
     * Falls back to the stored file only if live rendering throws.
     *
     * @throws \Throwable  If both live render and stored file are unavailable.
     */
    public function getDownloadResponse(Certificate $certificate): Response
    {
        try {
            $pdfBytes = $this->renderPdf($certificate);
        } catch (\Throwable $e) {
            Log::error('[Certificate] Live render failed — falling back to stored file', [
                'certificate_id' => $certificate->id,
                'error'          => $e->getMessage(),
            ]);

            if ($certificate->pdf_path && Storage::disk('local')->exists($certificate->pdf_path)) {
                $pdfBytes = Storage::disk('local')->get($certificate->pdf_path);
            } else {
                Log::error('[Certificate] No stored PDF fallback available', [
                    'certificate_id' => $certificate->id,
                    'pdf_path'       => $certificate->pdf_path,
                ]);
                throw $e;
            }
        }

        $safeCode = preg_replace('/[^A-Za-z0-9\-_]/', '', $certificate->certificate_code ?? (string) $certificate->id);
        $filename = 'certificate-' . $safeCode . '.pdf';

        Log::info('[Certificate] Serving download response', [
            'certificate_id'   => $certificate->id,
            'certificate_code' => $certificate->certificate_code,
            'filename'         => $filename,
            'size_bytes'       => strlen($pdfBytes),
        ]);

        return response($pdfBytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => strlen($pdfBytes),
            'Cache-Control'       => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function fallbackConfig(bool $forPdf = true): array
    {
        return [
            'blade_path'             => 'certificates.templates.default',
            'for_pdf'                => $forPdf,
            'org_name_en'            => 'Educational Mastar Central',
            'org_name_ar'            => 'مركز ماستر التعليمي',
            'font_arabic'            => 'Cairo',
            'font_english'           => 'Poppins',
            'color_primary'          => '#0D2741',
            'color_secondary'        => '#F57C00',
            'color_accent'           => '#009DDB',
            'color_text'             => '#1A1A1A',
            'show_admin_signature'   => true,
            'show_program_signature' => true,
            'show_official_stamp'    => false,
            'admin_sig_name_en'      => 'Tareq Tuaayman',
            'admin_sig_name_ar'      => 'طارق طعيمان',
            'admin_sig_title_en'     => 'Founder & General Manager – EMC',
            'admin_sig_title_ar'     => 'مؤسس ومدير عام – EMC',
            'program_sig_name_ar'    => 'إدارة البرامج',
            'program_sig_title_ar'   => 'مركز ماستر التعليمي – EMC',
            'paper_size'             => 'A4',
            'orientation'            => 'landscape',
            'margin_top'             => 8,
            'margin_bottom'          => 8,
            'margin_left'            => 12,
            'margin_right'           => 12,
            'bg_style'               => 'default',
            'border_style'           => 'default',
        ];
    }
}
