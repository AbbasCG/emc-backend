<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * استقبال أحداث تحليلات الزوار — نقطة عامة خفيفة (sendBeacon).
 *
 * تقبل شكلين: دفعة `{events:[...]}` من متتبع الصفحات/الأخطاء، أو حدث قمع §17
 * مفرد بشكل `{event: name, ...props}` كما يرسله funnelEvents. الموافقة تُفرض
 * في المتصفح (لا يُرسل شيء قبل قبول التحليلات)؛ هنا نتحقق من الشكل فقط.
 */
class AnalyticsIngestController extends Controller
{
    private const MAX_BATCH = 25;

    public function store(Request $request): JsonResponse
    {
        $body = $request->all();

        $rows = isset($body['events']) && is_array($body['events'])
            ? array_slice($body['events'], 0, self::MAX_BATCH)
            : [$body];

        [$device, $browser, $os] = $this->parseUserAgent((string) $request->userAgent());
        $userId = $request->user('sanctum')?->id;
        $stored = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = $row['type'] ?? (isset($row['event']) ? 'event' : null);
            if (! in_array($type, ['pageview', 'event', 'error'], true)) {
                continue;
            }

            AnalyticsEvent::create([
                'visitor_id' => Str::limit((string) ($row['visitor_id'] ?? ''), 64, '') ?: null,
                'session_id' => Str::limit((string) ($row['session_id'] ?? ''), 64, '') ?: null,
                'user_id'    => $userId,
                'type'       => $type,
                'name'       => Str::limit((string) ($row['name'] ?? $row['event'] ?? ''), 255, '') ?: null,
                'path'       => Str::limit((string) ($row['path'] ?? ''), 500, '') ?: null,
                'referrer'   => Str::limit((string) ($row['referrer'] ?? ''), 500, '') ?: null,
                'utm_source'   => Str::limit((string) ($row['utm_source'] ?? ''), 255, '') ?: null,
                'utm_medium'   => Str::limit((string) ($row['utm_medium'] ?? ''), 255, '') ?: null,
                'utm_campaign' => Str::limit((string) ($row['utm_campaign'] ?? ''), 255, '') ?: null,
                'locale'     => Str::limit((string) ($row['locale'] ?? ''), 20, '') ?: null,
                'timezone'   => Str::limit((string) ($row['timezone'] ?? ''), 64, '') ?: null,
                'device'     => $device,
                'browser'    => $browser,
                'os'         => $os,
                'screen_w'   => is_numeric($row['screen_w'] ?? null) ? min(20000, (int) $row['screen_w']) : null,
                'screen_h'   => is_numeric($row['screen_h'] ?? null) ? min(20000, (int) $row['screen_h']) : null,
                'meta'       => $this->cleanMeta($row),
                'created_at' => now(),
            ]);
            $stored++;
        }

        return response()->json(['success' => true, 'stored' => $stored], 201);
    }

    /** خصائص الحدث الإضافية — دون الحقول البنيوية، وبحجم محدود. */
    private function cleanMeta(array $row): ?array
    {
        $structural = [
            'events', 'type', 'event', 'name', 'path', 'referrer', 'visitor_id', 'session_id',
            'utm_source', 'utm_medium', 'utm_campaign', 'locale', 'timezone', 'screen_w', 'screen_h',
        ];
        $meta = array_diff_key($row, array_flip($structural));
        if ($meta === []) {
            return null;
        }

        return array_slice(array_map(
            fn ($v) => is_scalar($v) ? Str::limit((string) $v, 500, '') : null,
            $meta,
        ), 0, 15, true);
    }

    /** استخلاص مبسّط للجهاز/المتصفح/النظام من الـUser-Agent — يكفي للوحة داخلية. */
    private function parseUserAgent(string $ua): array
    {
        $device = 'desktop';
        if (preg_match('/iPad|Tablet/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/Mobile|Android|iPhone/i', $ua)) {
            $device = 'mobile';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/')                                     => 'Edge',
            str_contains($ua, 'SamsungBrowser')                           => 'Samsung Internet',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')       => 'Opera',
            str_contains($ua, 'Firefox/')                                 => 'Firefox',
            str_contains($ua, 'Chrome/')                                  => 'Chrome',
            str_contains($ua, 'Safari/')                                  => 'Safari',
            default                                                       => 'أخرى',
        };

        $os = match (true) {
            str_contains($ua, 'Windows')                                  => 'Windows',
            str_contains($ua, 'Android')                                  => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')      => 'iOS',
            str_contains($ua, 'Mac OS')                                   => 'macOS',
            str_contains($ua, 'Linux')                                    => 'Linux',
            default                                                       => 'أخرى',
        };

        return [$device, $browser, $os];
    }
}
