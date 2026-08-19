<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\ResourceCenterFavorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only Resource Center (مركز الموارد) for internal staff to find, search,
 * filter, copy, and share course/workshop/learning-path links independently —
 * never touches enrollment or course-creation workflows.
 *
 * Listing/search/filter logic is entirely delegated to
 * AdminCourseController::index() (same query params) so the Resource Center
 * never re-implements the course query — it only augments the response with
 * public_url + is_favorite and exposes it under a broader role set.
 */
class ResourceCenterController extends Controller
{
    // GET /api/admin/resource-center/courses
    public function index(Request $request): JsonResponse
    {
        $response = app(AdminCourseController::class)->index($request);
        $payload = $response->getData(true);

        $courseIds = collect($payload['data'])->pluck('id')->all();
        $favoriteIds = ResourceCenterFavorite::where('user_id', $request->user()->id)
            ->whereIn('course_id', $courseIds)
            ->pluck('course_id')
            ->all();

        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

        $payload['data'] = collect($payload['data'])->map(function ($course) use ($favoriteIds, $frontendUrl) {
            $course['public_url'] = $frontendUrl . '/courses/' . $course['slug'];
            $course['is_favorite'] = in_array($course['id'], $favoriteIds, true);

            return $course;
        })->values();

        return response()->json($payload);
    }

    // GET /api/admin/resource-center/favorites
    public function favorites(Request $request): JsonResponse
    {
        $ids = ResourceCenterFavorite::where('user_id', $request->user()->id)->pluck('course_id');

        return response()->json(['success' => true, 'data' => $ids]);
    }

    // POST /api/admin/resource-center/courses/{course}/favorite
    public function toggleFavorite(Request $request, Course $course): JsonResponse
    {
        $favorite = ResourceCenterFavorite::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->first();

        if ($favorite) {
            $favorite->delete();

            return response()->json(['success' => true, 'data' => ['is_favorite' => false]]);
        }

        ResourceCenterFavorite::create(['user_id' => $request->user()->id, 'course_id' => $course->id]);

        return response()->json(['success' => true, 'data' => ['is_favorite' => true]]);
    }

    // GET /api/admin/resource-center/export
    public function export(Request $request)
    {
        $format = $request->query('format', 'csv') === 'xlsx' ? 'xlsx' : 'csv';

        $ids = array_filter(array_map('intval', (array) $request->query('selected', [])));
        $query = ! empty($ids)
            ? Course::with(['instructor.user', 'track'])->whereIn('id', $ids)
            : $this->reuseFilteredQuery($request);

        $rows = $query->get()->map(fn (Course $c) => [
            'العنوان' => $c->title,
            'النوع' => $c->program_type,
            'الحالة' => $c->status,
            'المدرب' => $c->instructor?->user?->name ?? '—',
            'التصنيف' => $c->track?->title ?? '—',
            'الرابط العام' => rtrim(config('app.frontend_url', config('app.url')), '/') . '/courses/' . $c->slug,
            'تاريخ الإنشاء' => optional($c->created_at)->format('Y-m-d'),
        ]);

        $filename = 'course-library-' . now()->format('Y-m-d') . '.' . $format;

        if ($format === 'xlsx') {
            return $this->streamXlsx($rows, $filename);
        }

        return $this->streamCsv($rows, $filename);
    }

    private function reuseFilteredQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = Course::with(['instructor.user', 'track']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('program_type')) {
            $query->where('program_type', $request->query('program_type'));
        }
        if ($request->filled('instructor_id')) {
            $query->where('instructor_id', $request->query('instructor_id'));
        }
        if ($request->filled('track_id')) {
            $query->where('track_id', $request->query('track_id'));
        }
        if ($request->filled('search')) {
            $q = (string) $request->query('search');
            $query->where(fn ($sub) => $sub
                ->where('title', 'like', "%{$q}%")
                ->orWhere('slug', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")
            );
        }

        return $query;
    }

    private function streamCsv($rows, string $filename)
    {
        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            if ($rows->isNotEmpty()) {
                fputcsv($handle, array_keys($rows->first()));
            }
            foreach ($rows as $row) {
                fputcsv($handle, array_values($row));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function streamXlsx($rows, string $filename)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('مكتبة الدورات');
        $sheet->setRightToLeft(true);

        $columns = $rows->isNotEmpty() ? array_keys($rows->first()) : [];
        foreach (array_values($columns) as $i => $label) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($colLetter . '1', $label);
        }
        if ($columns) {
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));
            $sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
        }

        $rowIndex = 2;
        foreach ($rows as $row) {
            $col = 1;
            foreach (array_values($row) as $value) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $sheet->setCellValueExplicit($colLetter . $rowIndex, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $col++;
            }
            $rowIndex++;
        }
        foreach (range(1, max(count($columns), 1)) as $col) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
