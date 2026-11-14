<?php

namespace App\Http\Requests;

use App\Services\UsersAnalytics\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The one window every users-analytics endpoint accepts: ?from=YYYY-MM-DD&to=YYYY-MM-DD&compare=1.
 *
 * Authorization is the route's `permission:` middleware, which is why authorize() is open. Presets (today, last 7 days…)
 * are resolved by the client into from/to so that one validated shape reaches the server.
 */
class UsersAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'from'    => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to'      => ['nullable', 'date_format:Y-m-d', 'required_with:from'],
            'compare' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->hasAny(['from', 'to']) || ! $this->filled('from')) {
                return;
            }

            $tz = (string) config('users-analytics.timezone', 'UTC');
            $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->input('from'), $tz)->startOfDay();
            $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->input('to'), $tz)->startOfDay();

            if ($from->gt($to)) {
                $v->errors()->add('from', 'تاريخ البداية يجب ألا يكون بعد تاريخ النهاية.');

                return;
            }
            if ($to->gt(CarbonImmutable::now($tz)->startOfDay())) {
                $v->errors()->add('to', 'لا يمكن أن يكون تاريخ النهاية في المستقبل.');
            }
            $max = (int) config('users-analytics.max_range_days', 366);
            // plain calendar dates, so a DST day does not skew the span
            $span = CarbonImmutable::createFromFormat('Y-m-d', $from->toDateString(), 'UTC')->diffInDays(CarbonImmutable::createFromFormat('Y-m-d', $to->toDateString(), 'UTC'));
            if ((int) round($span) + 1 > $max) {
                $v->errors()->add('to', "أطول فترة مسموحة {$max} يومًا.");
            }
        });
    }

    public function period(): AnalyticsPeriod
    {
        if (! $this->filled('from')) {
            return AnalyticsPeriod::lastDays((int) config('users-analytics.default_range_days', 30));
        }

        return AnalyticsPeriod::make((string) $this->input('from'), (string) $this->input('to'));
    }

    public function wantsComparison(): bool
    {
        return $this->boolean('compare');
    }
}
