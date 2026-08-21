<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\LearningPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * استيراد الكتالوج دفعة واحدة — يستقبل صفوف قالب الإكسل (تُحلَّل في المتصفح
 * وتصل JSON) ويثبّتها بالمعاينة أولًا:
 *
 *   dry_run=true  → تحقق فقط، صفًا صفًا، بلا أي كتابة.
 *   dry_run=false → upsert بالـslug داخل معاملة واحدة (الفشل يرجع كل شيء).
 *
 * الأنواع: «دورة» و«ورشة» تدخلان جدول الدورات (type=course|workshop)،
 * و«مسار» يدخل جدول المسارات. كل صف يعود بحالته: created|updated|error.
 */
class CatalogImportController extends Controller
{
    private const TYPES = ['دورة', 'ورشة', 'مسار'];
    private const LEVELS = ['مبتدئ' => 'beginner', 'متوسط' => 'intermediate', 'متقدم' => 'advanced'];
    private const STATUSES = ['منشور' => 'published', 'مسودة' => 'draft'];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dry_run'          => 'required|boolean',
            'rows'             => 'required|array|min:1|max:300',
            'rows.*'           => 'array',
        ]);

        $dryRun  = (bool) $data['dry_run'];
        $results = [];
        $userId  = $request->user()->id;

        $run = function () use ($data, $dryRun, $userId, &$results) {
            foreach ($data['rows'] as $i => $row) {
                $results[] = $this->importRow($i, $row, $dryRun, $userId);
            }
        };

        if ($dryRun) {
            $run();
        } else {
            DB::transaction($run);
        }

        $summary = [
            'total'   => count($results),
            'created' => count(array_filter($results, fn ($r) => $r['status'] === 'created')),
            'updated' => count(array_filter($results, fn ($r) => $r['status'] === 'updated')),
            'errors'  => count(array_filter($results, fn ($r) => $r['status'] === 'error')),
        ];

        return response()->json([
            'success' => true,
            'data'    => ['dry_run' => $dryRun, 'summary' => $summary, 'results' => $results],
        ]);
    }

    /** @param array<string, mixed> $row */
    private function importRow(int $index, array $row, bool $dryRun, int $userId): array
    {
        $rowNo = $index + 2; // +2: رأس الجدول صف 1 في الإكسل

        $type = trim((string) ($row['النوع'] ?? ''));
        if (! in_array($type, self::TYPES, true)) {
            return ['row' => $rowNo, 'status' => 'error', 'message' => 'النوع يجب أن يكون: دورة أو ورشة أو مسار'];
        }

        $title = trim((string) ($row['العنوان'] ?? ''));
        if ($title === '' || mb_strlen($title) < 3) {
            return ['row' => $rowNo, 'status' => 'error', 'message' => 'العنوان لازم (3 أحرف فأكثر)'];
        }

        $slug = Str::slug(trim((string) ($row['المعرف اللاتيني (slug)'] ?? '')));
        if ($slug === '') {
            return ['row' => $rowNo, 'status' => 'error', 'message' => 'المعرف اللاتيني (slug) لازم — أحرف إنجليزية وشرطات، وهو مفتاح التحديث'];
        }

        $priceRaw = trim((string) ($row['السعر باليورو'] ?? ''));
        $price    = $priceRaw === '' ? 0.0 : (float) str_replace(['€', ','], '', $priceRaw);
        if ($price < 0 || $price > 100000) {
            return ['row' => $rowNo, 'status' => 'error', 'message' => 'السعر خارج النطاق المقبول'];
        }

        $levelAr = trim((string) ($row['المستوى'] ?? ''));
        $level   = $levelAr === '' ? 'beginner' : (self::LEVELS[$levelAr] ?? null);
        if ($level === null) {
            return ['row' => $rowNo, 'status' => 'error', 'message' => 'المستوى يجب أن يكون: مبتدئ أو متوسط أو متقدم'];
        }

        $statusAr = trim((string) ($row['الحالة'] ?? ''));
        $status   = $statusAr === '' ? 'draft' : (self::STATUSES[$statusAr] ?? null);
        if ($status === null) {
            return ['row' => $rowNo, 'status' => 'error', 'message' => 'الحالة يجب أن تكون: منشور أو مسودة'];
        }

        $startDate = trim((string) ($row['تاريخ البدء (YYYY-MM-DD)'] ?? ''));
        if ($startDate !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            return ['row' => $rowNo, 'status' => 'error', 'message' => 'تاريخ البدء بصيغة YYYY-MM-DD أو اتركه فارغًا'];
        }

        $capacityRaw = trim((string) ($row['السعة (عدد المقاعد)'] ?? ''));
        $capacity    = $capacityRaw === '' ? null : (int) $capacityRaw;

        $common = [
            'title'             => $title,
            'short_description' => trim((string) ($row['الوصف المختصر'] ?? '')) ?: null,
            'price'             => $price,
            'currency'          => 'EUR',
            'level'             => $level,
            'status'            => $status,
            'language'          => trim((string) ($row['اللغة'] ?? '')) ?: 'العربية',
        ];

        if ($type === 'مسار') {
            $duration = trim((string) ($row['المدة'] ?? ''));
            $existing = LearningPath::where('slug', $slug)->first();
            $payload  = [
                ...$common,
                'full_description'  => trim((string) ($row['الوصف الكامل'] ?? '')) ?: ($common['short_description'] ?? $title),
                'duration'          => (int) preg_replace('/\D/', '', $duration) ?: null,
                'duration_unit'     => str_contains($duration, 'أسبوع') ? 'weeks' : 'months',
                'certificate_name'  => trim((string) ($row['اسم الشهادة'] ?? '')) ?: null,
                'enrollment_open'   => $status === 'published',
                'learning_outcomes' => $this->splitList($row['المخرجات (افصل بفاصلة منقوطة ؛)'] ?? ''),
            ];

            if (! $dryRun) {
                LearningPath::updateOrCreate(
                    ['slug' => $slug],
                    [...$payload, 'created_by' => $existing?->created_by ?? $userId, 'updated_by' => $userId],
                );
            }

            return ['row' => $rowNo, 'status' => $existing ? 'updated' : 'created', 'message' => "مسار: {$title}"];
        }

        // دورة / ورشة → جدول الدورات
        $existing = Course::where('slug', $slug)->first();
        $payload  = [
            ...$common,
            'description'       => trim((string) ($row['الوصف الكامل'] ?? '')) ?: ($common['short_description'] ?? $title),
            'type'              => $type === 'ورشة' ? 'workshop' : 'course',
            'is_free'           => $price <= 0,
            'is_paid'           => $price > 0,
            'is_online'         => true,
            'start_date'        => $startDate ?: null,
            'start_time'        => trim((string) ($row['وقت البدء (HH:MM)'] ?? '')) ?: null,
            'capacity'          => $capacity,
            'duration'          => trim((string) ($row['المدة'] ?? '')) ?: null,
            'training_hours'    => (int) trim((string) ($row['الساعات التدريبية'] ?? '')) ?: null,
            'certificate'       => trim((string) ($row['اسم الشهادة'] ?? '')) ?: null,
            'certificate_available' => trim((string) ($row['اسم الشهادة'] ?? '')) !== '',
            'target_audience'   => trim((string) ($row['الفئة المستهدفة'] ?? '')) ?: null,
            'learning_outcomes' => $this->splitList($row['المخرجات (افصل بفاصلة منقوطة ؛)'] ?? ''),
            'requirements'      => $this->splitList($row['المتطلبات (افصل بفاصلة منقوطة ؛)'] ?? ''),
            'registration_open' => $status === 'published',
        ];

        if (! $dryRun) {
            Course::updateOrCreate(
                ['slug' => $slug],
                [...$payload, 'created_by' => $existing?->created_by ?? $userId, 'updated_by' => $userId],
            );
        }

        return [
            'row'     => $rowNo,
            'status'  => $existing ? 'updated' : 'created',
            'message' => ($type === 'ورشة' ? 'ورشة: ' : 'دورة: ') . $title,
        ];
    }

    /** «أ؛ ب؛ ج» → ['أ','ب','ج'] — بالفاصلة المنقوطة العربية أو اللاتينية. */
    private function splitList(mixed $value): ?array
    {
        $parts = array_values(array_filter(array_map(
            'trim',
            preg_split('/[;؛]/u', (string) $value) ?: [],
        )));

        return $parts === [] ? null : $parts;
    }
}
