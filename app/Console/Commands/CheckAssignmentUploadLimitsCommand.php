<?php

namespace App\Console\Commands;

use App\Services\AssignmentUploads\AssignmentUploadPolicy;
use App\Services\AssignmentUploads\PhpUploadLimits;
use Illuminate\Console\Command;

/**
 * Compares the PHP runtime's upload ceilings with config/assignment_uploads.php, because PHP rejects an upload long
 * before Laravel can apply the application's own limits (the root cause of the "The file failed to upload." report).
 *
 * Exit code 0 = every check OK, 1 = at least one FAIL (usable in a deploy pipeline).
 */
class CheckAssignmentUploadLimitsCommand extends Command
{
    protected $signature = 'assignments:check-upload-limits';

    protected $description = 'Check that PHP upload limits (upload_max_filesize, post_max_size, max_file_uploads) allow the assignment upload limits / فحص توافق إعدادات PHP مع حدود رفع الواجبات';

    public function handle(AssignmentUploadPolicy $policy, PhpUploadLimits $php): int
    {
        $mb = 1048576;
        $perFile = $policy->maxPerFileBytes();
        $total = $policy->maxTotalBytes();
        $needPost = $total + $policy->overheadBytes();
        $maxFiles = $policy->maxFiles();

        $recommendedUpload = (int) ceil($perFile / $mb);
        $recommendedPost = (int) (ceil(($needPost / $mb) / 8) * 8);

        $this->line('Assignment upload limits / حدود رفع الواجبات');
        $this->line(sprintf(
            '  App config: max_files=%d, per-file max=%s, total max=%s, multipart overhead=%s',
            $maxFiles,
            $this->mb($perFile),
            $this->mb($total),
            $this->mb($policy->overheadBytes()),
        ));
        $this->line(sprintf('  PHP SAPI=%s, php.ini=%s', $php->sapi(), $php->iniFile()));
        $this->newLine();

        $failed = false;

        // 0) The app's own configuration must be coherent (a misconfiguration would otherwise be a confusing failure on the first submit).
        $configErrors = $policy->configErrors();
        foreach ($configErrors as $error) {
            $this->error('[FAIL] config/assignment_uploads.php: '.$error);
        }
        if ($configErrors === []) {
            $this->info('[OK] config/assignment_uploads.php is consistent / الإعدادات متسقة');
        }
        $failed = $configErrors !== [];

        // 0b) Required PHP extensions.
        foreach (PhpUploadLimits::REQUIRED_EXTENSIONS as $extension) {
            $loaded = $php->extensionLoaded($extension);
            $failed = ! $loaded || $failed;
            $line = sprintf('[%s] PHP extension %s / امتداد PHP %s', $loaded ? 'OK' : 'FAIL', $extension, $loaded ? 'محمّل' : 'غير محمّل: مطلوب لرفع الواجبات');
            $loaded ? $this->info($line) : $this->error($line);
        }

        // 1) upload_max_filesize >= largest per-file limit
        $upload = $php->uploadMaxFilesizeBytes();
        $ok = $upload >= $perFile && $upload > 0;
        $failed = ! $ok || $failed;
        $this->report(
            $ok,
            'upload_max_filesize',
            $php->raw('upload_max_filesize'),
            "{$recommendedUpload}M",
            'يجب أن يكون ≥ أكبر حد لملف واحد ('.$this->mb($perFile).')',
            'must be >= the largest per-file limit ('.$this->mb($perFile).')',
        );

        // 2) post_max_size >= total + multipart overhead (0 = unlimited)
        $post = $php->postMaxBytes();
        $ok = $post === 0 || $post >= $needPost;
        $failed = ! $ok || $failed;
        $this->report(
            $ok,
            'post_max_size',
            $php->raw('post_max_size'),
            "{$recommendedPost}M",
            'يجب أن يكون ≥ الحجم الإجمالي + هامش الترويسات ('.$this->mb($needPost).')',
            'must be >= total limit + multipart overhead ('.$this->mb($needPost).')',
        );

        // 3) max_file_uploads >= max files per submission
        $count = $php->maxFileUploads();
        $ok = $count >= $maxFiles;
        $failed = ! $ok || $failed;
        $this->report(
            $ok,
            'max_file_uploads',
            (string) $count,
            (string) $maxFiles,
            "يجب أن يكون ≥ أقصى عدد ملفات ({$maxFiles})",
            "must be >= max files per submission ({$maxFiles})",
        );

        $this->newLine();
        $this->line("Nginx / Nginx: client_max_body_size {$recommendedPost}m;  (must be >= post_max_size; also check proxy/CDN body limits and timeouts)");
        $this->line('Note / ملاحظة: this command reads the CLI php.ini. PHP-FPM / Apache may use a different file; verify the web SAPI values too (php-fpm -i, or phpinfo behind auth).');

        if ($failed) {
            $this->error('RESULT: FAIL / النتيجة: فشل — upload limits are lower than the application limits. Fix php.ini, then reload PHP-FPM and Nginx.');

            return self::FAILURE;
        }

        $this->info('RESULT: OK / النتيجة: سليم');

        return self::SUCCESS;
    }

    private function report(bool $ok, string $key, string $current, string $required, string $ar, string $en): void
    {
        $line = sprintf('[%s] %s = %s  (required/المطلوب: %s) — %s / %s', $ok ? 'OK' : 'FAIL', $key, $current === '' ? '(unset)' : $current, $required, $ar, $en);
        $ok ? $this->info($line) : $this->error($line);
    }

    private function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.').'MB';
    }
}
