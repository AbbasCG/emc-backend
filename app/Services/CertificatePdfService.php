<?php

namespace App\Services;

use App\Models\Certificate;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Low-level DomPDF wrapper.
 *
 * Responsibilities:
 *  - convertToPdf(): render cfg+data via Blade and convert to PDF bytes
 *  - renderBlade(): render cfg+data via Blade to HTML (for template preview only)
 *  - verificationUrl(): build verification URL for a certificate
 *
 * Do NOT add business logic here.
 * Use CertificateRenderService for all orchestration.
 */
class CertificatePdfService
{
    /**
     * Render Blade template + convert to PDF bytes via DomPDF.
     *
     * @param  array  $cfg   Template config (from CertificateGeneratorService::buildTemplateConfig())
     * @param  array  $data  Certificate data  (from CertificateGeneratorService::buildTemplateData())
     * @return string        Raw PDF binary content
     */
    public function convertToPdf(array $cfg, array $data): string
    {
        $bladePath      = $this->sanitizeBladePath($cfg['blade_path'] ?? null);
        $cfg['for_pdf'] = true;

        $html        = view($bladePath, compact('cfg', 'data'))->render();
        $orientation = $cfg['orientation'] ?? 'landscape';
        $paperSize   = strtolower($cfg['paper_size'] ?? 'a4');

        return Pdf::loadHTML($html)
            ->setPaper($paperSize, $orientation)
            ->setOption([
                'dpi'                     => 150,
                'isRemoteEnabled'         => true,
                'isFontSubsettingEnabled' => true,
                'defaultFont'             => 'sans-serif',
                'isHtml5ParserEnabled'    => true,
            ])
            ->output();
    }

    /**
     * Render Blade template as HTML string — FOR PREVIEW / DESIGNER ONLY.
     *
     * Do NOT use for certificate generation or download.
     * Use CertificateRenderService for those operations.
     */
    public function renderBlade(array $cfg, array $data): string
    {
        $bladePath      = $this->sanitizeBladePath($cfg['blade_path'] ?? null);
        $cfg['for_pdf'] = false;

        return view($bladePath, compact('cfg', 'data'))->render();
    }

    /**
     * Restrict blade_path to the certificates.templates.* namespace.
     * Prevents rendering of arbitrary sensitive views.
     */
    private function sanitizeBladePath(?string $path): string
    {
        $default = 'certificates.templates.default';

        if (! $path) {
            return $default;
        }

        // Must start with 'certificates.templates.' and contain only safe chars
        if (preg_match('/^certificates\.templates\.[a-zA-Z0-9_\-]+$/', $path)) {
            return $path;
        }

        \Illuminate\Support\Facades\Log::warning('[Certificate] Unsafe blade_path rejected', ['path' => $path]);

        return $default;
    }

    /**
     * Public verification URL for a certificate.
     */
    public function verificationUrl(Certificate $certificate): string
    {
        return url('/api/certificates/verify/' . ($certificate->verification_code ?? ''));
    }
}
