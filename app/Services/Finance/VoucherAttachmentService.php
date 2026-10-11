<?php

namespace App\Services\Finance;

use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Private storage for manual expense / revenue voucher attachments.
 *
 * Financial documents are stored on the `private` disk (never web-served) and
 * are streamed only through the authorized download / preview endpoints,
 * mirroring ManualPaymentController::downloadProof(). The stored path is
 * generated server-side and re-validated against its directory before any read,
 * so a tampered path can never reach outside it.
 */
class VoucherAttachmentService
{
    public const DISK = 'private';

    /** Store an uploaded attachment; returns [path, original name]. */
    public function store(UploadedFile $file, string $directory): array
    {
        return [
            $file->store($directory, self::DISK),
            $this->safeName($file->getClientOriginalName()),
        ];
    }

    public function delete(?string $path, string $directory): void
    {
        if ($path && $this->isSafePath($path, $directory)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Stream the voucher's attachment (download, or inline preview) and record
     * the access in the audit log. Authorization is the caller's job.
     */
    public function respond(Model $voucher, string $directory, string $label, bool $inline): Response
    {
        $path = $voucher->attachment_path;

        if (! $path || ! $this->isSafePath($path, $directory) || ! Storage::disk(self::DISK)->exists($path)) {
            return response()->json(['success' => false, 'message' => 'لا يوجد مرفق لهذا السند.'], 404);
        }

        $type = class_basename($voucher);
        AuditLogService::log(
            $inline ? 'finance.voucher_attachment_previewed' : 'finance.voucher_attachment_downloaded',
            $type,
            $voucher->getKey(),
            $label,
        );

        $name = $voucher->attachment_name ? $this->safeName($voucher->attachment_name) : basename($path);
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

        return $inline
            ? Storage::disk(self::DISK)->response($path, $name, $headers, 'inline')
            : Storage::disk(self::DISK)->download($path, $name, $headers);
    }

    /** Only files the store() call above could have produced: "<dir>/<hash>.<ext>". */
    private function isSafePath(string $path, string $directory): bool
    {
        return preg_match('#\A'.preg_quote($directory, '#').'/[A-Za-z0-9]{1,100}\.[A-Za-z0-9]{1,8}\z#', $path) === 1;
    }

    private function safeName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', basename(str_replace('\\', '/', $name))) ?? '');

        return $name !== '' ? mb_substr($name, 0, 200) : 'attachment';
    }
}
