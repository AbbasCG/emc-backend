<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The page a deployment ticket's assignee must test on the LIVE site: a public https URL, never a credential-bearing,
 * IP-literal, localhost or single-label (internal) address. When config('tickets.live_hosts') is not empty the host must
 * also equal one of those hosts or be a subdomain of one.
 */
class LiveUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('رابط الموقع الحي غير صالح.');

            return;
        }

        $parts = parse_url($value);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || $parts['host'] === '') {
            $fail('رابط الموقع الحي يجب أن يكون رابطًا صحيحًا يبدأ بـ https://');

            return;
        }

        // user:password@host would let a link look like one site and open another (or carry a secret).
        if (isset($parts['user']) || isset($parts['pass']) || str_contains(explode('/', explode('://', $value, 2)[1] ?? '', 2)[0], '@')) {
            $fail('رابط الموقع الحي لا يجوز أن يحتوي على اسم مستخدم أو كلمة مرور.');

            return;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false || $host === 'localhost' || ! str_contains($host, '.') || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            $fail('رابط الموقع الحي يجب أن يكون عنوان موقع عام (وليس عنوان IP أو localhost أو اسمًا محليًا).');

            return;
        }

        $allowed = array_filter((array) config('tickets.live_hosts', []));
        if ($allowed !== [] && ! collect($allowed)->contains(fn ($h) => $host === $h || str_ends_with($host, '.'.$h))) {
            $fail('رابط الموقع الحي ليس ضمن النطاقات المسموح بها لهذه المنصة.');
        }
    }
}
