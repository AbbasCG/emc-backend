<?php

namespace App\Services\Forms;

use App\Jobs\GenerateFormExport;
use App\Models\DynamicForm;
use App\Models\FormExport;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\Export\CsvExportWriter;
use App\Services\Forms\Export\FormExportColumns;
use App\Services\Forms\Export\FormResponseDataset;
use App\Services\Forms\Export\PdfExportWriter;
use App\Services\Forms\Export\XlsxExportWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Exports of a form's responses.
 *
 * AUTHORIZATION IS THE SAME AS READING THE RESPONSES, deliberately: there is no export permission. Whoever may read a form's
 * responses (FormAccessService::canViewResponses) may export exactly the rows those filters select and the columns they pick
 * from what that same person sees on screen; nobody else may export anything. It is checked when the export is asked for, again when
 * the file is built (the grant may have been withdrawn while it waited in the queue), and again when it is downloaded.
 *
 * Files are written to the PRIVATE disk under a server-generated name, belong to the person who asked, are served only through an
 * authenticated endpoint, and are deleted when they expire. Small exports are built at once; larger ones go to the queue.
 */
class FormExportService
{
    public const DISK = 'private';

    public const DIRECTORY = 'form-exports';

    /** Beyond these an export is built in the queue instead of during the request. */
    public const INLINE_MAX_ROWS = 500;

    public const INLINE_MAX_CELLS = 5000;

    /** Hard limits per format. CSV streams, so only the number of rows bounds it; a workbook and a PDF are built in memory. */
    public const CSV_MAX_ROWS = 100000;

    public const XLSX_MAX_CELLS = 60000;

    /** Measured on a scratch MariaDB: about 0.25 MB per row of long text, so 500 rows stay well inside the raised ceiling below. */
    public const PDF_MAX_ROWS = 500;

    /** A workbook or a PDF is built in memory; while one is being built the ceiling is raised to this (CSV never needs it). */
    public const MEMORY_CEILING_MB = 512;

    public const PDF_MAX_COLUMNS = 12;

    /** Unfinished exports one person may have at once. */
    public const MAX_ACTIVE_PER_USER = 3;

    /** An export still unfinished after this long was never picked up (no worker running) or died: it counts as failed, not as waiting. */
    public const STALE_MINUTES = 30;

    public function __construct(private readonly FormAccessService $access) {}

    /**
     * @param  array<string, mixed>  $filters  normalised (FormResponseQuery::normalize)
     * @param  list<string>|null  $columns  the chosen column keys; null means the default selection
     *
     * @throws ValidationException
     */
    public function request(DynamicForm $form, User $user, string $format, array $filters, ?array $columns, ?Request $request = null): FormExport
    {
        if (! in_array($format, FormExport::FORMATS, true)) {
            throw ValidationException::withMessages(['format' => 'صيغة التصدير غير مدعومة. اختر XLSX أو CSV أو PDF.']);
        }
        if (isset($filters['version']) && ! FormVersion::query()->where('form_id', $form->id)->whereKey($filters['version'])->exists()) {
            throw ValidationException::withMessages(['version' => 'الإصدار المحدد لا ينتمي إلى هذا النموذج.']);
        }

        $catalog = FormExportColumns::forForm($form, $filters['version'] ?? null);
        $known = FormExportColumns::keys($catalog);
        if ($columns !== null) {
            $unknown = array_diff($columns, $known);
            if ($unknown !== [] || $columns === []) {
                throw ValidationException::withMessages(['columns' => $columns === [] ? 'اختر عموداً واحداً على الأقل للتصدير.' : 'تتضمن الأعمدة المحددة عموداً غير متاح لهذا النموذج.']);
            }
        }

        $dataset = new FormResponseDataset($form, $filters, $columns);
        $selected = array_column($dataset->columns(), 'key'); // in the catalog's order, whatever order they were sent in
        $rows = $dataset->count();
        $this->assertWithinLimits($format, $rows, count($selected));

        if (FormExport::query()->where('user_id', $user->id)->whereIn('status', [FormExport::QUEUED, FormExport::PROCESSING])
            ->where('created_at', '>', now()->subMinutes(self::STALE_MINUTES))->count() >= self::MAX_ACTIVE_PER_USER) {
            throw ValidationException::withMessages(['format' => 'لديك عمليات تصدير قيد التنفيذ. انتظر حتى تنتهي ثم أعد المحاولة.']);
        }

        $export = FormExport::create([
            'form_id' => $form->id, 'user_id' => $user->id, 'format' => $format, 'status' => FormExport::QUEUED,
            'filters' => $filters, 'columns' => $selected, 'row_count' => $rows,
            'file_name' => sprintf('form-%d-responses-%s.%s', $form->id, now()->format('Ymd-His'), $format),
        ]);
        FormActivityService::record($form, $user, 'responses_exported', ['format' => $format, 'rows' => $rows], $request);

        $inline = $rows <= self::INLINE_MAX_ROWS && $rows * count($selected) <= self::INLINE_MAX_CELLS;
        $inline ? GenerateFormExport::dispatchSync($export->id) : GenerateFormExport::dispatch($export->id);

        return $export->refresh();
    }

    /**
     * Builds the file. Runs inline or in the queue; never throws: a failure is recorded on the export, with words for the person
     * and no internal detail.
     */
    public function generate(FormExport $export): void
    {
        $export->forceFill(['status' => FormExport::PROCESSING, 'error' => null])->save();
        $path = null;

        try {
            $form = DynamicForm::query()->find($export->form_id);
            $user = User::query()->find($export->user_id);

            // The grant is judged now, not only when the export was asked for.
            if ($form === null || $user === null || ! $user->is_active || ! $this->access->canViewResponses($user, $form)) {
                $this->fail($export, 'لم تعد تملك صلاحية عرض إجابات هذا النموذج، فلم يُنشأ الملف.');

                return;
            }

            $this->allowMemoryFor($export->format);
            $dataset = new FormResponseDataset($form, (array) $export->filters, (array) $export->columns);
            $relative = self::DIRECTORY.'/'.$form->id.'/'.Str::uuid()->toString().'.'.$export->format;
            Storage::disk(self::DISK)->makeDirectory(self::DIRECTORY.'/'.$form->id);
            $path = Storage::disk(self::DISK)->path($relative);

            $meta = ['title' => $form->title, 'subtitle' => 'تم التصدير في '.now()->timezone(FormResponseQuery::DISPLAY_TIMEZONE)->format('Y-m-d H:i').' بواسطة '.$user->name, 'filters' => $this->describeFilters((array) $export->filters, $dataset)];
            $written = match ($export->format) {
                FormExport::CSV  => (new CsvExportWriter)->write($path, $dataset, $meta),
                FormExport::XLSX => (new XlsxExportWriter)->write($path, $dataset, $meta),
                FormExport::PDF  => (new PdfExportWriter)->write($path, $dataset, $meta),
            };

            $export->forceFill([
                'status' => FormExport::READY, 'file_path' => $relative, 'file_size' => filesize($path) ?: null, 'row_count' => $written,
                'completed_at' => now(), 'expires_at' => now()->addHours(FormExport::TTL_HOURS),
            ])->save();
        } catch (\Throwable $e) {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
            report($e);
            $this->fail($export, 'تعذّر إنشاء ملف التصدير. حاول مرة أخرى، وإن تكرر ذلك فاختر صيغة CSV أو عدداً أقل من الأعمدة.');
        }
    }

    /** Deletes the files of exports past their expiry (and of old failures). Returns how many were removed. */
    public function purgeExpired(): int
    {
        $count = 0;
        // Nobody is waiting for these any more, and a person must not stay locked out by them: say so in the record.
        FormExport::query()->whereIn('status', [FormExport::QUEUED, FormExport::PROCESSING])
            ->where('created_at', '<=', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => FormExport::FAILED, 'error' => 'لم يكتمل إنشاء الملف في الوقت المناسب. أنشئ تصديراً جديداً.']);

        FormExport::query()
            ->where(fn ($q) => $q->where('status', FormExport::READY)->where('expires_at', '<=', now())
                ->orWhere(fn ($f) => $f->where('status', FormExport::FAILED)->where('updated_at', '<=', now()->subDay())))
            ->chunkById(100, function ($exports) use (&$count) {
                foreach ($exports as $export) {
                    if ($export->file_path !== null) {
                        Storage::disk(self::DISK)->delete($export->file_path);
                    }
                    $export->forceFill(['status' => FormExport::EXPIRED, 'file_path' => null])->save();
                    $count++;
                }
            });

        return $count;
    }

    private function allowMemoryFor(string $format): void
    {
        if ($format === FormExport::CSV) {
            return;
        }
        $limit = trim((string) ini_get('memory_limit'));
        $bytes = (int) $limit;
        $bytes *= match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };
        if ($bytes > 0 && $bytes < self::MEMORY_CEILING_MB * 1024 ** 2) {
            @ini_set('memory_limit', self::MEMORY_CEILING_MB.'M');
        }
    }

    private function fail(FormExport $export, string $message): void
    {
        $export->forceFill(['status' => FormExport::FAILED, 'error' => $message, 'file_path' => null])->save();
    }

    private function assertWithinLimits(string $format, int $rows, int $columns): void
    {
        if ($rows === 0) {
            throw ValidationException::withMessages(['format' => 'لا توجد ردود مطابقة للتصفية الحالية لتصديرها.']);
        }
        if ($format === FormExport::CSV && $rows > self::CSV_MAX_ROWS) {
            throw ValidationException::withMessages(['format' => 'عدد الردود يتجاوز الحد الأقصى للتصدير ('.number_format(self::CSV_MAX_ROWS).'). ضيّق التصفية بنطاق تاريخ.']);
        }
        if ($format === FormExport::XLSX && $rows * $columns > self::XLSX_MAX_CELLS) {
            throw ValidationException::withMessages(['format' => 'حجم البيانات كبير على ملف XLSX. اختر أعمدة أقل أو ضيّق التصفية أو استخدم صيغة CSV.']);
        }
        if ($format === FormExport::PDF && ($rows > self::PDF_MAX_ROWS || $columns > self::PDF_MAX_COLUMNS)) {
            throw ValidationException::withMessages(['format' => 'ملف PDF مخصص للطباعة: بحد أقصى '.self::PDF_MAX_ROWS.' رد و'.self::PDF_MAX_COLUMNS.' عموداً. ضيّق التصفية أو اختر أعمدة أقل، أو استخدم XLSX أو CSV.']);
        }
    }

    /**
     * What the file was narrowed by, in words, for the header of a PDF.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function describeFilters(array $filters, FormResponseDataset $dataset): array
    {
        $lines = [];
        if (isset($filters['search'])) {
            $lines[] = 'بحث: '.$filters['search'];
        }
        if (! empty($filters['status'])) {
            $lines[] = 'الحالة: '.implode('، ', array_map(fn ($s) => FormExportColumns::statusLabel($s), $filters['status']));
        }
        if (isset($filters['from']) || isset($filters['to'])) {
            $lines[] = 'الفترة: '.($filters['from'] ?? '…').' → '.($filters['to'] ?? '…');
        }
        if (isset($filters['version'])) {
            $lines[] = 'الإصدار: '.$filters['version'];
        }

        return $lines;
    }
}
