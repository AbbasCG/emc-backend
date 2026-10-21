<?php

namespace App\Services\Forms;

use App\Models\FormQuestion;

/**
 * What a question is allowed to carry, per type, and how a builder payload becomes the columns that are stored.
 *
 * The builder is untrusted input: every property is checked against the question's type, unknown keys are dropped, and
 * anything that does not make sense for the type (a scale on a text question, a maximum below a minimum, a default that is
 * not one of the options) is refused with a message naming the field. What survives is stored in `config` and read back
 * by the public form and by FormSubmissionService, which enforces the same limits when answers arrive: the browser is
 * never the only place a limit exists.
 *
 * Structural blocks (section heading, descriptive text, page break) carry text only and are never required.
 */
final class FormQuestionConfig
{
    public const DEFAULT_STARS = 5;

    public const MIN_STARS = 3;

    public const MAX_STARS = 10;

    public const LABEL_MAX = 255;

    public const DESCRIPTION_MAX = 2000;

    public const PLACEHOLDER_MAX = 255;

    public const HELP_MAX = 500;

    public const SCALE_LABEL_MAX = 60;

    /**
     * @param  array<string, mixed>  $item  one question as the builder sent it
     * @return array{attributes: array<string, mixed>, errors: array<string, string>}
     */
    public static function normalize(array $item): array
    {
        $type = (string) ($item['type'] ?? '');
        $errors = [];
        $structural = in_array($type, FormQuestion::STRUCTURAL, true);

        $label = trim((string) ($item['label'] ?? ''));
        $description = self::text($item['description'] ?? null);
        $placeholder = self::text($item['placeholder'] ?? null);
        $help = self::text($item['help_text'] ?? null);

        if (! in_array($type, ['description', 'page_break'], true) && $label === '') {
            $errors['label'] = 'نص السؤال مطلوب.';
        }
        if ($type === 'description' && $description === null) {
            $errors['description'] = 'اكتب نص الفقرة التوضيحية.';
        }
        foreach ([['label', $label, self::LABEL_MAX, 'نص السؤال'], ['description', $description, self::DESCRIPTION_MAX, 'الوصف'], ['placeholder', $placeholder, self::PLACEHOLDER_MAX, 'النص التوضيحي داخل الحقل'], ['help_text', $help, self::HELP_MAX, 'نص المساعدة']] as [$field, $value, $max, $name]) {
            if ($value !== null && mb_strlen((string) $value) > $max) {
                $errors[$field] = "{$name} أطول من المسموح ({$max} حرفاً).";
            }
        }

        $options = FormQuestionSyncService::normalizeOptions($type, $item['options'] ?? null);
        $raw = is_array($item['config'] ?? null) ? $item['config'] : [];
        [$config, $configErrors] = self::config($type, $raw, $options);

        return [
            'attributes' => [
                'label'       => $label,
                'type'        => $type,
                'options'     => $options,
                'is_required' => $structural ? false : (bool) ($item['is_required'] ?? $item['required'] ?? false),
                'description' => $description,
                'placeholder' => in_array($type, ['text', 'textarea', 'email', 'phone', 'number', 'url', 'select'], true) ? $placeholder : null,
                'help_text'   => $structural ? null : $help,
                'config'      => $config === [] ? null : $config,
            ],
            'errors' => $errors + $configErrors,
        ];
    }

    /** The star count of a rating question. */
    public static function stars(FormQuestion $q): int
    {
        return (int) $q->setting('scale.max', self::DEFAULT_STARS);
    }

    /** The [min, max] of a linear scale question. */
    public static function scaleBounds(FormQuestion $q): array
    {
        return [(int) $q->setting('scale.min', 1), (int) $q->setting('scale.max', 5)];
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private static function config(string $type, array $raw, ?array $options): array
    {
        $config = [];
        $errors = [];
        $validation = is_array($raw['validation'] ?? null) ? $raw['validation'] : [];
        $scale = is_array($raw['scale'] ?? null) ? $raw['scale'] : [];
        $default = $raw['default_value'] ?? null;

        $int = function (array $src, string $key, string $path, int $min, int $max, string $name) use (&$errors): ?int {
            if (! array_key_exists($key, $src) || $src[$key] === null || $src[$key] === '') {
                return null;
            }
            if (filter_var($src[$key], FILTER_VALIDATE_INT) === false || (int) $src[$key] < $min || (int) $src[$key] > $max) {
                $errors[$path] = "{$name} يجب أن يكون رقماً صحيحاً بين {$min} و{$max}.";

                return null;
            }

            return (int) $src[$key];
        };

        switch ($type) {
            case 'text':
            case 'textarea':
                $cap = $type === 'text' ? FormSubmissionService::TEXT_MAX : FormSubmissionService::TEXTAREA_MAX;
                $minLen = $int($validation, 'min_length', 'config.validation.min_length', 0, $cap, 'الحد الأدنى للطول');
                $maxLen = $int($validation, 'max_length', 'config.validation.max_length', 1, $cap, 'الحد الأقصى للطول');
                if ($minLen !== null && $maxLen !== null && $minLen > $maxLen) {
                    $errors['config.validation.max_length'] = 'الحد الأقصى للطول يجب ألا يقل عن الحد الأدنى.';
                }
                self::put($config, 'validation.min_length', $minLen);
                self::put($config, 'validation.max_length', $maxLen);
                self::defaultText($config, $errors, $default, $maxLen ?? $cap);
                break;

            case 'email':
            case 'phone':
            case 'url':
                self::defaultText($config, $errors, $default, 255);
                break;

            case 'number':
                $min = self::number($validation, 'min', 'config.validation.min', $errors, 'الحد الأدنى');
                $max = self::number($validation, 'max', 'config.validation.max', $errors, 'الحد الأعلى');
                if ($min !== null && $max !== null && $min > $max) {
                    $errors['config.validation.max'] = 'الحد الأعلى يجب ألا يقل عن الحد الأدنى.';
                }
                self::put($config, 'validation.min', $min);
                self::put($config, 'validation.max', $max);
                if (! empty($validation['integer_only'])) {
                    $config['validation']['integer_only'] = true;
                }
                if ($default !== null && $default !== '') {
                    if (! is_numeric($default)) {
                        $errors['config.default_value'] = 'القيمة الافتراضية يجب أن تكون رقماً.';
                    } elseif (($min !== null && $default < $min) || ($max !== null && $default > $max)) {
                        $errors['config.default_value'] = 'القيمة الافتراضية خارج الحدود المسموحة.';
                    } else {
                        $config['default_value'] = $default + 0;
                    }
                }
                break;

            case 'date':
                $min = self::date($validation, 'min_date', 'config.validation.min_date', $errors, 'أقدم تاريخ');
                $max = self::date($validation, 'max_date', 'config.validation.max_date', $errors, 'أحدث تاريخ');
                if ($min !== null && $max !== null && $min > $max) {
                    $errors['config.validation.max_date'] = 'أحدث تاريخ يجب ألا يسبق أقدم تاريخ.';
                }
                self::put($config, 'validation.min_date', $min);
                self::put($config, 'validation.max_date', $max);
                if ($default !== null && $default !== '') {
                    if (self::validDate((string) $default) === false) {
                        $errors['config.default_value'] = 'القيمة الافتراضية ليست تاريخاً صالحاً.';
                    } else {
                        $config['default_value'] = (string) $default;
                    }
                }
                break;

            case 'time':
                if ($default !== null && $default !== '') {
                    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $default) !== 1) {
                        $errors['config.default_value'] = 'القيمة الافتراضية ليست وقتاً صالحاً (HH:MM).';
                    } else {
                        $config['default_value'] = (string) $default;
                    }
                }
                break;

            case 'radio':
            case 'select':
                if ($default !== null && $default !== '') {
                    if (! is_string($default) || ! in_array($default, $options ?? [], true)) {
                        $errors['config.default_value'] = 'القيمة الافتراضية يجب أن تكون أحد الخيارات.';
                    } else {
                        $config['default_value'] = $default;
                    }
                }
                break;

            case 'checkbox':
            case 'multiselect':
                $count = count($options ?? FormQuestion::CHECKBOX_DEFAULT_OPTIONS);
                $minC = $int($validation, 'min_choices', 'config.validation.min_choices', 0, $count, 'الحد الأدنى للاختيارات');
                $maxC = $int($validation, 'max_choices', 'config.validation.max_choices', 1, max(1, $count), 'الحد الأقصى للاختيارات');
                if ($minC !== null && $maxC !== null && $minC > $maxC) {
                    $errors['config.validation.max_choices'] = 'الحد الأقصى للاختيارات يجب ألا يقل عن الحد الأدنى.';
                }
                self::put($config, 'validation.min_choices', $minC);
                self::put($config, 'validation.max_choices', $maxC);
                if (is_array($default) && $default !== []) {
                    $allowed = $options ?? FormQuestion::CHECKBOX_DEFAULT_OPTIONS;
                    $clean = array_values(array_unique(array_map('strval', $default)));
                    if (array_diff($clean, $allowed) !== []) {
                        $errors['config.default_value'] = 'القيم الافتراضية يجب أن تكون من الخيارات.';
                    } else {
                        $config['default_value'] = $clean;
                    }
                }
                break;

            case 'yes_no':
                if ($default !== null && $default !== '') {
                    if (! in_array($default, ['yes', 'no'], true)) {
                        $errors['config.default_value'] = 'القيمة الافتراضية يجب أن تكون نعم أو لا.';
                    } else {
                        $config['default_value'] = $default;
                    }
                }
                break;

            case 'star_rating':
                $max = $int($scale, 'max', 'config.scale.max', self::MIN_STARS, self::MAX_STARS, 'عدد النجوم');
                self::put($config, 'scale.max', $max);
                break;

            case 'linear_scale':
                $min = $int($scale, 'min', 'config.scale.min', 0, 1, 'بداية المقياس');
                $max = $int($scale, 'max', 'config.scale.max', 2, 10, 'نهاية المقياس');
                if (($min ?? 1) >= ($max ?? 5)) {
                    $errors['config.scale.max'] = 'نهاية المقياس يجب أن تكون أكبر من بدايته.';
                }
                self::put($config, 'scale.min', $min);
                self::put($config, 'scale.max', $max);
                foreach (['min_label' => 'عنوان البداية', 'max_label' => 'عنوان النهاية'] as $key => $name) {
                    $value = self::text($scale[$key] ?? null);
                    if ($value !== null && mb_strlen($value) > self::SCALE_LABEL_MAX) {
                        $errors["config.scale.{$key}"] = "{$name} أطول من المسموح (".self::SCALE_LABEL_MAX.' حرفاً).';
                    } else {
                        self::put($config, "scale.{$key}", $value);
                    }
                }
                break;

            default:
                // consent, file, image and the structural blocks carry no extra settings
                break;
        }

        return [$config, $errors];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** Sets a dotted path in the config only when there is a value. */
    private static function put(array &$config, string $path, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        data_set($config, $path, $value);
    }

    private static function number(array $src, string $key, string $path, array &$errors, string $name): int|float|null
    {
        if (! array_key_exists($key, $src) || $src[$key] === null || $src[$key] === '') {
            return null;
        }
        if (! is_numeric($src[$key]) || abs((float) $src[$key]) > 1_000_000_000_000) {
            $errors[$path] = "{$name} يجب أن يكون رقماً صالحاً.";

            return null;
        }

        return $src[$key] + 0;
    }

    private static function date(array $src, string $key, string $path, array &$errors, string $name): ?string
    {
        if (! array_key_exists($key, $src) || $src[$key] === null || $src[$key] === '') {
            return null;
        }
        if (! is_string($src[$key]) || self::validDate($src[$key]) === false) {
            $errors[$path] = "{$name} ليس تاريخاً صالحاً.";

            return null;
        }

        return $src[$key];
    }

    private static function validDate(string $value): bool
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }

    private static function defaultText(array &$config, array &$errors, mixed $default, int $max): void
    {
        if ($default === null || $default === '') {
            return;
        }
        if (! is_string($default) || mb_strlen($default) > $max) {
            $errors['config.default_value'] = 'القيمة الافتراضية غير صالحة أو أطول من المسموح.';

            return;
        }
        $config['default_value'] = $default;
    }
}
