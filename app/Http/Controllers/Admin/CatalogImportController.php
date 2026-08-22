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
 * استيراد الكتالوج دفعة واحدة — كل أنواع المنتجات من قالب إكسل واحد بكامل
 * التفاصيل (الكود، الأهداف، المحاور، أيام الدراسة، …):
 *
 *   دورة · ورشة · ورشة مجانية · معسكر · تحدي · هاكثون · زمالة  → جدول الدورات
 *   مسار                                                        → جدول المسارات
 *
 *   dry_run=true  → تحقق فقط، صفًا صفًا، بلا أي كتابة.
 *   dry_run=false → upsert بالـslug داخل معاملة واحدة (الفشل يرجع كل شيء).
 */
class CatalogImportController extends Controller
{
    /** النوع العربي → [جدول، type، program_type] */
    private const KIND_MAP = [
        'دورة'        => ['course', 'course', 'course'],
        'ورشة'        => ['course', 'workshop', 'workshop'],
        'ورشة مجانية' => ['course', 'workshop', 'free_workshop'],
        'معسكر'       => ['course', 'course', 'camp'],
        'تحدي'        => ['course', 'course', 'challenge'],
        'هاكثون'      => ['course', 'course', 'hackathon'],
        'زمالة'       => ['course', 'course', 'fellowship'],
        'مسار'        => ['path', null, null],
    ];

    private const LEVELS = ['مبتدئ' => 'beginner', 'متوسط' => 'intermediate', 'متقدم' => 'advanced'];
    private const STATUSES = ['منشور' => 'published', 'مسودة' => 'draft'];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dry_run' => 'required|boolean',
            'rows'    => 'required|array|min:1|max:300',
            'rows.*'  => 'array',
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
        $rowNo = $index + 2; // رأس الجدول صف 1 في الإكسل

        $type = trim((string) ($row['النوع'] ?? ''));
        if (! isset(self::KIND_MAP[$type])) {
            return ['row' => $rowNo, 'status' => 'error', 'message' => 'النوع يجب أن يكون: دورة، ورشة، ورشة مجانية، معسكر، تحدي، هاكثون، زمالة، أو مسار'];
        }
        [$table, $courseType, $programType] = self::KIND_MAP[$type];

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
        if ($type === 'ورشة مجانية') {
            $price = 0.0;
        }
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

        $code       = trim((string) ($row['الكود'] ?? '')) ?: null;
        $objectives = $this->splitList($row['الأهداف والمخرجات (افصل بفاصلة منقوطة ؛)'] ?? '');
        $modules    = $this->splitList($row['المحاور (افصل بفاصلة منقوطة ؛)'] ?? '');
        $studyDays  = $this->splitList($row['أيام الدراسة (؛)'] ?? '');
        $studyTime  = trim((string) ($row['وقت الدراسة'] ?? '')) ?: null;

        $common = [
            'catalog_code'      => $code,
            'title'             => $title,
            'short_description' => trim((string) ($row['الوصف المختصر'] ?? '')) ?: null,
            'price'             => $price,
            'currency'          => 'EUR',
            'level'             => $level,
            'status'            => $status,
            'language'          => trim((string) ($row['اللغة'] ?? '')) ?: 'العربية',
        ];

        if ($table === 'path') {
            $duration = trim((string) ($row['المدة'] ?? ''));
            $existing = LearningPath::where('slug', $slug)->first();

            $fullDescription = trim((string) ($row['الوصف الكامل'] ?? '')) ?: ($common['short_description'] ?? $title);
            if ($modules) {
                $fullDescription .= "\n\nمحاور المسار:\n- " . implode("\n- ", $modules);
            }

            $payload = [
                ...$common,
                'full_description'  => $fullDescription,
                'duration'          => (int) preg_replace('/\D/', '', $duration) ?: null,
                'duration_unit'     => str_contains($duration, 'أسبوع') ? 'weeks' : 'months',
                'certificate_name'  => trim((string) ($row['اسم الشهادة'] ?? '')) ?: null,
                'enrollment_open'   => $status === 'published',
                'learning_outcomes' => $objectives,
                'requirements'      => $this->splitList($row['المتطلبات (افصل بفاصلة منقوطة ؛)'] ?? ''),
                'study_days'        => $studyDays,
                'study_time'        => $studyTime,
            ];

            if (! $dryRun) {
                LearningPath::updateOrCreate(
                    ['slug' => $slug],
                    [...$payload, 'created_by' => $existing?->created_by ?? $userId, 'updated_by' => $userId],
                );
            }

            return ['row' => $rowNo, 'status' => $existing ? 'updated' : 'created', 'message' => "مسار: {$title}"];
        }

        // كل الأنواع الأخرى → جدول الدورات، بنوعها الحقيقي في program_type
        $capacityRaw = trim((string) ($row['السعة (عدد المقاعد)'] ?? ''));
        $existing    = Course::where('slug', $slug)->first();

        $payload = [
            ...$common,
            'description'           => trim((string) ($row['الوصف الكامل'] ?? '')) ?: ($common['short_description'] ?? $title),
            'type'                  => $courseType,
            'program_type'          => $programType,
            'is_free'               => $price <= 0,
            'is_paid'               => $price > 0,
            'is_online'             => true,
            'start_date'            => $startDate ?: null,
            'start_time'            => trim((string) ($row['وقت البدء (HH:MM)'] ?? '')) ?: null,
            'end_date'              => trim((string) ($row['تاريخ الانتهاء (YYYY-MM-DD)'] ?? '')) ?: null,
            'capacity'              => $capacityRaw === '' ? null : (int) $capacityRaw,
            'duration'              => trim((string) ($row['المدة'] ?? '')) ?: null,
            'training_hours'        => (int) trim((string) ($row['الساعات التدريبية'] ?? '')) ?: null,
            'certificate'           => trim((string) ($row['اسم الشهادة'] ?? '')) ?: null,
            'certificate_available' => trim((string) ($row['اسم الشهادة'] ?? '')) !== '',
            'target_audience'       => trim((string) ($row['الفئة المستهدفة'] ?? '')) ?: null,
            'learning_outcomes'     => $objectives,
            'requirements'          => $this->splitList($row['المتطلبات (افصل بفاصلة منقوطة ؛)'] ?? ''),
            'curriculum_topics'     => $modules,
            // study_days في جدول الدورات عمود نصي (بخلاف المسارات حيث هو مصفوفة)
            'study_days'            => $studyDays ? implode('، ', $studyDays) : null,
            'study_time'            => $studyTime,
            'whatsapp_community_url' => trim((string) ($row['رابط مجتمع واتساب'] ?? '')) ?: null,
            'registration_open'     => $status === 'published',
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
            'message' => "{$type}: {$title}" . ($code ? " ({$code})" : ''),
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
