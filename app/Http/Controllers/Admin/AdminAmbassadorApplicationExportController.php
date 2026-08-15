<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AmbassadorApplication;
use App\Services\AmbassadorApplicationExportColumns;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Ambassador Applications export center — reporting/export only, never
 * touches the approval workflow, status transitions, or any other existing
 * behavior on AdminAmbassadorApplicationController. Deliberately NOT limited
 * to approved applications; every status is exportable.
 *
 * Row generation is synchronous + chunked (query never loads more than
 * $chunkSize rows into memory at once) rather than a queued job — this
 * environment's actual QUEUE_CONNECTION is `sync` (.env), so a ShouldQueue
 * export job would still run inline in the same request with no real async
 * benefit; chunked streaming is the honest, correct choice here.
 */
class AdminAmbassadorApplicationExportController extends Controller
{
    private const MAX_ROWS = 10000;
    private const CHUNK_SIZE = 500;

    // GET /api/admin/ambassador-applications/export/options
    public function options(): JsonResponse
    {
        $countries = AmbassadorApplication::where('is_draft', false)
            ->whereNotNull('country')->where('country', '!=', '')
            ->distinct()->orderBy('country')->pluck('country');

        $universities = AmbassadorApplication::where('is_draft', false)
            ->whereNotNull('university_name')->where('university_name', '!=', '')
            ->distinct()->orderBy('university_name')->pluck('university_name');

        $universityTypes = AmbassadorApplication::where('is_draft', false)
            ->whereNotNull('university_type')->where('university_type', '!=', '')
            ->distinct()->orderBy('university_type')->pluck('university_type');

        $majors = AmbassadorApplication::where('is_draft', false)
            ->whereNotNull('major')->where('major', '!=', '')
            ->distinct()->orderBy('major')->pluck('major');

        $studyYears = AmbassadorApplication::where('is_draft', false)
            ->whereNotNull('study_year')->where('study_year', '!=', '')
            ->distinct()->orderBy('study_year')->pluck('study_year');

        $registry = AmbassadorApplicationExportColumns::registry();
        $columns = collect($registry)->map(fn ($c, $key) => [
            'key' => $key, 'label' => $c['label'], 'group' => $c['group'],
        ])->values();

        return response()->json(['success' => true, 'data' => [
            // Read dynamically from the model — never hardcoded, so any future
            // status added to AmbassadorApplication::allowedStatuses() shows up automatically.
            'statuses'          => AmbassadorApplication::allowedStatuses(),
            'countries'         => $countries,
            'universities'      => $universities,
            'university_types'  => $universityTypes,
            'majors'            => $majors,
            'study_years'       => $studyYears,
            'columns'           => $columns,
            'column_groups'     => AmbassadorApplicationExportColumns::groups(),
            'default_columns'   => AmbassadorApplicationExportColumns::defaultColumns(),
            'max_rows'          => self::MAX_ROWS,
        ]]);
    }

    // POST /api/admin/ambassador-applications/export/preview
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $query = $this->buildQuery($data);
        $count = min($query->count(), self::MAX_ROWS);
        $columnCount = count($data['columns'] ?? AmbassadorApplicationExportColumns::defaultColumns());

        // Rough, honest estimate — not a promise: ~180 bytes/cell for CSV,
        // XLSX carries more structural overhead per row.
        $bytesPerCell = $data['format'] === 'xlsx' ? 140 : 90;
        $estimatedBytes = $count * $columnCount * $bytesPerCell;

        return response()->json(['success' => true, 'data' => [
            'records' => $count,
            'truncated' => $query->count() > self::MAX_ROWS,
            'columns' => $columnCount,
            'estimated_size_bytes' => $estimatedBytes,
        ]]);
    }

    // POST /api/admin/ambassador-applications/export
    public function export(Request $request)
    {
        $data = $this->validateExport($request);
        $format = $data['format'];
        $columns = $data['columns'];
        $registry = AmbassadorApplicationExportColumns::registry();

        $query = $this->buildQuery($data)->limit(self::MAX_ROWS);

        AuditLogService::log(
            action: 'EXPORT',
            entityType: 'AmbassadorApplication',
            entityId: null,
            entityName: null,
            description: 'تصدير طلبات سفراء التحول الرقمي (' . $format . ', ' . count($columns) . ' عمود)',
        );

        $filename = $this->buildFilename($data, $format);

        if ($format === 'xlsx') {
            return $this->streamXlsx($query, $columns, $registry, $filename);
        }

        return $this->streamCsv($query, $columns, $registry, $filename);
    }

    // ─── Query building ──────────────────────────────────────────────────────

    private function rules(): array
    {
        return [
            'format' => 'required|string|in:csv,xlsx',
            'mode' => 'required|string|in:all,filtered,selected,search',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'search' => 'nullable|string|max:255',
            'statuses' => 'nullable|array',
            'statuses.*' => 'string',
            'countries' => 'nullable|array',
            'universities' => 'nullable|array',
            'university_types' => 'nullable|array',
            'majors' => 'nullable|array',
            'study_years' => 'nullable|array',
            'date_field' => 'nullable|string|in:created_at,reviewed_at,status_updated_at,updated_at',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'columns' => 'nullable|array',
            'columns.*' => 'string',
        ];
    }

    private function validateExport(Request $request): array
    {
        $data = $request->validate($this->rules());
        $registry = AmbassadorApplicationExportColumns::registry();
        $columns = array_values(array_filter(
            $data['columns'] ?? AmbassadorApplicationExportColumns::defaultColumns(),
            fn ($c) => isset($registry[$c]),
        ));
        if (empty($columns)) {
            $columns = AmbassadorApplicationExportColumns::defaultColumns();
        }
        $data['columns'] = $columns;

        return $data;
    }

    private function buildQuery(array $data)
    {
        $query = AmbassadorApplication::query()->where('is_draft', false)->with('reviewer');

        if ($data['mode'] === 'selected' && ! empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);

            return $query;
        }

        if ($data['mode'] === 'search' && ! empty($data['search'])) {
            $search = $data['search'];
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($sub) use ($like) {
                $sub->where('full_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('mobile_phone', 'like', $like)
                    ->orWhere('university_name', 'like', $like)
                    ->orWhere('country', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('major', 'like', $like);
            });
        }

        if (! empty($data['statuses'])) {
            $query->whereIn('status', $data['statuses']);
        }
        if (! empty($data['countries'])) {
            $query->whereIn('country', $data['countries']);
        }
        if (! empty($data['universities'])) {
            $query->whereIn('university_name', $data['universities']);
        }
        if (! empty($data['university_types'])) {
            $query->whereIn('university_type', $data['university_types']);
        }
        if (! empty($data['majors'])) {
            $query->whereIn('major', $data['majors']);
        }
        if (! empty($data['study_years'])) {
            $query->whereIn('study_year', $data['study_years']);
        }

        $dateField = $data['date_field'] ?? 'created_at';
        if (! empty($data['date_from'])) {
            $query->whereDate($dateField, '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate($dateField, '<=', $data['date_to']);
        }

        return $query->orderBy('id');
    }

    private function buildFilename(array $data, string $format): string
    {
        $date = now()->format('Y-m-d');
        $suffix = '';
        if (! empty($data['statuses']) && count($data['statuses']) === 1) {
            $suffix = '-' . $data['statuses'][0];
        } elseif (! empty($data['countries']) && count($data['countries']) === 1) {
            $suffix = '-' . \Illuminate\Support\Str::slug($data['countries'][0]);
        }
        $ext = $format === 'xlsx' ? 'xlsx' : 'csv';

        return "ambassador-applications{$suffix}-{$date}.{$ext}";
    }

    // ─── Streaming writers ───────────────────────────────────────────────────

    private function streamCsv($query, array $columns, array $registry, string $filename)
    {
        return response()->streamDownload(function () use ($query, $columns, $registry) {
            $handle = fopen('php://output', 'w');
            // UTF-8 BOM so Excel/Google Sheets/LibreOffice all render Arabic correctly.
            fwrite($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, array_map(fn ($c) => $registry[$c]['label'], $columns));

            $query->chunk(self::CHUNK_SIZE, function ($apps) use ($handle, $columns) {
                foreach ($apps as $app) {
                    $row = AmbassadorApplicationExportColumns::resolveRow($app, $columns);
                    fputcsv($handle, array_values($row));
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function streamXlsx($query, array $columns, array $registry, string $filename)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // Exact worksheet name required by spec — Excel caps sheet names at 31 chars, this fits.
        $sheet->setTitle('طلبات سفراء التحول الرقمي');
        $sheet->setRightToLeft(true);

        foreach (array_values($columns) as $i => $key) {
            $colLetter = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($colLetter . '1', $registry[$key]['label']);
        }
        $lastColLetter = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getStyle('A1:' . $lastColLetter . '1')->getFont()->setBold(true);

        $rowIndex = 2;
        $query->chunk(self::CHUNK_SIZE, function ($apps) use ($sheet, $columns, &$rowIndex) {
            foreach ($apps as $app) {
                $row = AmbassadorApplicationExportColumns::resolveRow($app, $columns);
                $col = 1;
                foreach ($row as $value) {
                    $colLetter = Coordinate::stringFromColumnIndex($col);
                    // Cast explicitly to string so PhpSpreadsheet never mangles a
                    // phone number/leading-zero value into a numeric cell.
                    $sheet->setCellValueExplicit($colLetter . $rowIndex, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $col++;
                }
                $rowIndex++;
            }
        });

        foreach (range(1, count($columns)) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
