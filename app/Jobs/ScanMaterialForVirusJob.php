<?php

namespace App\Jobs;

use App\Models\CourseMaterial;
use App\Models\MaterialAuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ClamAV antivirus scan. Requires clamdscan or clamd socket.
 * Enabled via MATERIALS_ANTIVIRUS_ENABLED=true in .env.
 */
class ScanMaterialForVirusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function __construct(private CourseMaterial $material) {}

    public function handle(): void
    {
        $material = $this->material->fresh();
        if (! $material || ! $material->file_path) {
            return;
        }

        $disk = Storage::disk($material->disk ?? 'private');
        if (! $disk->exists($material->file_path)) {
            return;
        }

        // Write to temp file for clamd
        $tempFile = tempnam(sys_get_temp_dir(), 'emc_av_');
        file_put_contents($tempFile, $disk->get($material->file_path));

        try {
            $status = $this->scan($tempFile);

            $material->update([
                'virus_scanned_at' => now(),
                'virus_status'     => $status,
            ]);

            MaterialAuditLog::record($material, 'virus_scanned', metadata: ['result' => $status]);

            if ($status === 'infected') {
                // Remove the infected file immediately
                $disk->delete($material->file_path);
                $material->update(['processing_status' => 'infected', 'is_visible' => false]);
                Log::critical("INFECTED FILE DETECTED: material #{$material->id} — {$material->original_filename}");
            }
        } finally {
            @unlink($tempFile);
        }
    }

    /** Returns 'clean' or 'infected'. Falls back to 'unknown' if clamd unavailable. */
    private function scan(string $path): string
    {
        $socket = config('materials.antivirus.socket');
        $host   = config('materials.antivirus.host', '127.0.0.1');
        $port   = config('materials.antivirus.port', 3310);

        // Try clamdscan binary first (fastest)
        if (function_exists('exec') && $this->commandExists('clamdscan')) {
            $output   = [];
            $exitCode = 0;
            exec("clamdscan --no-summary " . escapeshellarg($path) . " 2>&1", $output, $exitCode);
            return $exitCode === 0 ? 'clean' : 'infected';
        }

        // Fallback: TCP socket
        try {
            $fp = @fsockopen($host, $port, $errno, $errstr, 5);
            if (! $fp) {
                return 'unknown';
            }

            fwrite($fp, "INSTREAM\n");
            $chunk = file_get_contents($path);
            $len   = pack('N', strlen($chunk));
            fwrite($fp, $len . $chunk);
            fwrite($fp, pack('N', 0)); // end of stream

            $response = fgets($fp, 4096);
            fclose($fp);

            return str_contains($response, 'FOUND') ? 'infected' : 'clean';
        } catch (\Throwable $e) {
            Log::warning('ClamAV scan failed: ' . $e->getMessage());
            return 'unknown';
        }
    }

    private function commandExists(string $cmd): bool
    {
        $output = shell_exec("command -v " . escapeshellarg($cmd) . " 2>/dev/null");
        return ! empty(trim((string) $output));
    }

    public function failed(\Throwable $e): void
    {
        $this->material->fresh()?->update(['virus_status' => 'scan_failed']);
        Log::error("ScanMaterialForVirusJob failed for material #{$this->material->id}: " . $e->getMessage());
    }
}
