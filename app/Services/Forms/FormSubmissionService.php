<?php

namespace App\Services\Forms;

use App\Exceptions\FormUnavailableException;
use App\Models\DynamicForm;
use App\Models\FormAnswer;
use App\Models\FormQuestion;
use App\Models\FormSubmission;
use App\Models\User;
use App\Rules\FormAttachmentFile;
use App\Services\Forms\Automation\FormAutomationService;
use App\Services\Forms\Logic\FormLogic;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validates and stores one public submission of a dynamic form.
 *
 * The rules are built from the form's own questions, so the server — not the browser — decides what a valid
 * answer is: required questions must be answered, choice answers must be one of the configured options, values
 * must have the shape their question type promises, and attachments must be real files of an allowed kind.
 * Errors come back as ordinary Laravel validation errors keyed `answers.<questionId>` with Arabic messages that
 * name the question, so the form can show each one next to the right field.
 *
 * VISIBILITY (Phase 5): what a respondent could see is decided HERE, from the answers in the request and the rules of the version
 * being answered (FormLogic), never from anything the browser claims. A block its rules hide is not validated (a hidden required
 * question cannot block a submission), its answer is thrown away if one was sent, and an attachment sent for it is never stored.
 */
class FormSubmissionService
{
    public const TEXT_MAX = 1000;

    public const TEXTAREA_MAX = 10000;

    public const METADATA_MAX_ENTRIES = 10;

    public const METADATA_VALUE_MAX = 255;

    public const METADATA_MAX_BYTES = 2048;

    public function __construct(
        private readonly FormAttachmentService $files,
        private readonly FormAvailabilityService $availability,
        private readonly FormShareService $shares,
    ) {}

    /**
     * Answers keyed by question id. The first version of the public form sent them as `q_<id>`; both spellings are
     * accepted so a browser tab opened before this fix keeps working. A plain id wins if both are present.
     *
     * @return array<string|int, mixed>
     */
    public static function normalizeAnswers(mixed $answers): array
    {
        if (! is_array($answers)) {
            return [];
        }

        $normalized = [];
        foreach ($answers as $key => $value) {
            if (is_string($key) && preg_match('/^q_(\d+)$/', $key, $m) === 1) {
                $normalized[(int) $m[1]] ??= $value;

                continue;
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $input  the request input with files merged in
     * @param  Collection<int, FormQuestion>|null  $questions  what to validate against: the questions of the published
     *                                                         version being answered (the live ones when omitted)
     * @param  bool  $applyLogic  false only for a partial set of questions (a correction), whose visibility the caller decides
     * @return array{answers: array<int|string, mixed>, metadata: array<string, scalar>|null, hidden: list<int>}
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validate(DynamicForm $form, array $input, ?Collection $questions = null, bool $applyLogic = true): array
    {
        // A non-list `answers` is left as it came so the `array` rule reports it, instead of being quietly emptied.
        $input['answers'] = isset($input['answers']) && ! is_array($input['answers']) ? $input['answers'] : self::normalizeAnswers($input['answers'] ?? []);

        $questions ??= $form->questions;
        $input['answers'] = $this->canonicalAnswers($questions, $input['answers']);

        $hidden = [];
        if ($applyLogic && is_array($input['answers'])) {
            [$questions, $input['answers'], $hidden] = $this->applyVisibility($questions, $input['answers']);
        }

        [$rules, $messages] = $this->rulesFor($questions);
        Validator::make($input, $rules, $messages)->validate();

        $metadata = $input['metadata'] ?? null;

        return ['answers' => $input['answers'], 'metadata' => is_array($metadata) && $metadata !== [] ? $metadata : null, 'hidden' => $hidden];
    }

    /**
     * Keeps only what the respondent could see: the visible questions, and the answers to them.
     *
     * @param  Collection<int, FormQuestion>  $questions
     * @param  array<int|string, mixed>  $answers
     * @return array{0: Collection<int, FormQuestion>, 1: array<int|string, mixed>, 2: list<int>} the visible questions, their answers, and the ids that were hidden
     */
    public function applyVisibility(Collection $questions, array $answers): array
    {
        $ordered = $questions->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
        if ($ordered->every(fn (FormQuestion $q) => empty($q->logic))) {
            return [$questions, $answers, []]; // no rules anywhere: nothing to evaluate, nothing changes
        }

        $visible = FormLogic::visibility(
            $ordered->map(fn (FormQuestion $q) => ['id' => (int) $q->id, 'type' => $q->type, 'options' => $q->allowedOptions(), 'logic' => $q->logic])->all(),
            collect($answers)->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all(),
        );

        $hidden = [];
        foreach ($questions as $q) {
            if (! ($visible[(int) $q->id] ?? true)) {
                $hidden[] = (int) $q->id;
                unset($answers[$q->id], $answers[(string) $q->id]);
            }
        }

        return [$questions->reject(fn (FormQuestion $q) => in_array((int) $q->id, $hidden, true))->values(), $answers, $hidden];
    }

    /**
     * Stores a validated submission, but only if the form is still accepting answers from this respondent at the moment
     * of writing. The form row is locked for the duration, so the maximum number of submissions (and the per-respondent
     * limit) is checked and the row inserted as one step: requests that arrive together are admitted one after another and
     * the limit is never exceeded.
     *
     * @param  array{answers: array<int|string, mixed>, metadata: array<string, scalar>|null}  $validated
     * @param  Collection<int, FormQuestion>  $questions
     *
     * @throws FormUnavailableException
     */
    public function submit(DynamicForm $form, array $validated, ?User $user, Collection $questions, ?int $versionId, ?string $shareToken = null): FormSubmission
    {
        $submission = DB::transaction(function () use ($form, $validated, $user, $questions, $versionId, $shareToken) {
            $locked = DynamicForm::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();
            // The link is looked at again under the lock: it may have been revoked or used up since the page was opened, and
            // while the form row is held no other submission can slip in between this count and the insert below.
            $share = $this->shares->resolve($locked, $shareToken);
            $state = $this->availability->evaluate($locked, $user, $share);

            if ($state['state'] !== FormAvailabilityService::OPEN) {
                throw new FormUnavailableException($state['state'], (string) $state['message']);
            }

            return $this->store($locked, $validated, $user?->id, $questions, $versionId, $share->link?->id);
        });

        // Notifications, confirmation, automatic assignment and the webhook (Phase 8) run once the response is committed, and can
        // never make it fail.
        DB::afterCommit(fn () => app(FormAutomationService::class)->submitted($submission));

        return $submission;
    }

    /**
     * @param  array{answers: array<int|string, mixed>, metadata: array<string, scalar>|null}  $validated
     * @param  Collection<int, FormQuestion>|null  $questions
     */
    public function store(DynamicForm $form, array $validated, ?int $userId, ?Collection $questions = null, ?int $versionId = null, ?int $shareLinkId = null): FormSubmission
    {
        $storedPaths = [];
        $questions ??= $form->questions;

        try {
            return DB::transaction(function () use ($form, $validated, $userId, $questions, $versionId, $shareLinkId, &$storedPaths) {
                $submission = FormSubmission::create([
                    'form_id'         => $form->id,
                    'form_version_id' => $versionId,
                    'user_id'         => $userId,
                    'share_link_id'   => $shareLinkId,
                    'status'          => 'new',
                    'submitted_at'    => now(),
                    'metadata'        => $validated['metadata'],
                ]);

                foreach ($questions as $question) {
                    if ($question->isStructural()) {
                        continue; // headings, descriptions and page breaks are not answered
                    }
                    $raw = $validated['answers'][$question->id] ?? null;
                    $row = ['submission_id' => $submission->id, 'question_id' => $question->id, 'value' => null, 'file_path' => null];

                    if (in_array($question->type, ['file', 'image'], true)) {
                        if ($raw instanceof UploadedFile) {
                            $file = $this->files->store($raw, $form->id);
                            $storedPaths[] = $file['path'];
                            $row['file_path'] = $file['path'];
                            $row['value'] = $file['name'];
                        }
                    } else {
                        $row['value'] = $this->storableValue($question, $raw);
                    }

                    FormAnswer::create($row);
                }

                return $submission;
            });
        } catch (\Throwable $e) {
            // The submission rolled back: do not leave its files behind on the private disk.
            foreach ($storedPaths as $path) {
                $this->files->delete($path);
            }

            throw $e;
        }
    }

    /**
     * Yes / no and consent answers have several honest spellings (a checkbox sends `true` or `on`, an API sends `yes`).
     * They are reduced to the stored words before validation, so the rules below only ever see `yes` / `no`.
     *
     * @param  Collection<int, FormQuestion>  $questions
     */
    private function canonicalAnswers(Collection $questions, mixed $answers): mixed
    {
        if (! is_array($answers)) {
            return $answers;
        }

        foreach ($questions as $question) {
            if (! in_array($question->type, ['yes_no', 'consent'], true) || ! array_key_exists($question->id, $answers)) {
                continue;
            }
            $v = $answers[$question->id];
            $truthy = in_array($v, [true, 1, '1', 'true', 'on', 'yes', 'نعم'], true);
            $falsy = in_array($v, [false, 0, '0', 'false', 'off', 'no', 'لا'], true);
            if ($question->type === 'consent') {
                $answers[$question->id] = $truthy ? 'yes' : ($falsy ? null : $v);
            } else {
                $answers[$question->id] = $truthy ? 'yes' : ($falsy ? 'no' : $v);
            }
        }

        return $answers;
    }

    /** What goes into form_answers.value: plain text, or a JSON list for multi-choice questions. */
    public function storableValue(FormQuestion $question, mixed $raw): ?string
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        if ($question->isMulti()) {
            $list = array_values(array_unique(array_map('strval', (array) $raw)));

            return $list === [] ? null : json_encode($list, JSON_UNESCAPED_UNICODE);
        }

        return is_scalar($raw) ? trim((string) $raw) : null;
    }

    /**
     * @param  Collection<int, FormQuestion>  $questions
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function rulesFor(Collection $questions): array
    {
        $rules = [
            'answers'  => ['nullable', 'array'],
            'metadata' => ['nullable', 'array', $this->metadataRule()],
        ];
        $messages = [
            'answers.array'  => 'صيغة الإجابات غير صالحة.',
            'metadata.array' => 'بيانات الإرسال الإضافية غير صالحة.',
        ];

        foreach ($questions as $question) {
            if ($question->isStructural()) {
                continue;
            }

            $key = 'answers.'.$question->id;
            $label = $question->label;
            $presence = $question->is_required ? 'required' : 'nullable';

            $messages["$key.required"] = "حقل «{$label}» مطلوب.";

            switch ($question->type) {
                case 'email':
                    $rules[$key] = [$presence, 'string', 'email', 'max:255'];
                    $messages["$key.email"] = "أدخل بريداً إلكترونياً صالحاً في «{$label}».";
                    $messages["$key.string"] = "الإجابة في «{$label}» غير صالحة.";
                    $messages["$key.max"] = "الإجابة في «{$label}» أطول من المسموح.";
                    break;

                case 'phone':
                    $rules[$key] = [$presence, 'string', 'regex:/^\+?[0-9\s().\-]{6,30}$/'];
                    $messages["$key.regex"] = "أدخل رقم هاتف صالحاً في «{$label}».";
                    $messages["$key.string"] = "الإجابة في «{$label}» غير صالحة.";
                    break;

                case 'url':
                    $rules[$key] = [$presence, 'string', 'max:2048', 'regex:~^https?://[^\s/$.?#][^\s]*$~i'];
                    $messages["$key.regex"] = "أدخل رابطاً صالحاً يبدأ بـ http:// أو https:// في «{$label}».";
                    $messages["$key.max"] = "الرابط في «{$label}» أطول من المسموح.";
                    $messages["$key.string"] = "الإجابة في «{$label}» غير صالحة.";
                    break;

                case 'number':
                    $min = $question->setting('validation.min');
                    $max = $question->setting('validation.max');
                    $rules[$key] = [$presence, $question->setting('validation.integer_only') ? 'integer' : 'numeric', 'between:'.($min ?? -1000000000000).','.($max ?? 1000000000000)];
                    $messages["$key.numeric"] = "أدخل رقماً صالحاً في «{$label}».";
                    $messages["$key.integer"] = "أدخل رقماً صحيحاً (بلا كسور) في «{$label}».";
                    $messages["$key.between"] = $min !== null || $max !== null
                        ? "الرقم في «{$label}» يجب أن يكون بين ".($min ?? 'أي رقم').' و'.($max ?? 'أي رقم').'.'
                        : "الرقم في «{$label}» خارج الحدود المسموحة.";
                    break;

                case 'date':
                    $rules[$key] = [$presence, 'date_format:Y-m-d'];
                    if ($from = $question->setting('validation.min_date')) {
                        $rules[$key][] = 'after_or_equal:'.$from;
                        $messages["$key.after_or_equal"] = "التاريخ في «{$label}» يجب ألا يسبق {$from}.";
                    }
                    if ($to = $question->setting('validation.max_date')) {
                        $rules[$key][] = 'before_or_equal:'.$to;
                        $messages["$key.before_or_equal"] = "التاريخ في «{$label}» يجب ألا يتجاوز {$to}.";
                    }
                    $messages["$key.date_format"] = "أدخل تاريخاً صالحاً في «{$label}».";
                    break;

                case 'time':
                    $rules[$key] = [$presence, 'date_format:H:i'];
                    $messages["$key.date_format"] = "أدخل وقتاً صالحاً بصيغة HH:MM في «{$label}».";
                    break;

                case 'select':
                case 'radio':
                    $rules[$key] = [$presence, 'string', Rule::in($question->allowedOptions())];
                    $messages["$key.in"] = "اختر قيمة من الخيارات المتاحة في «{$label}».";
                    $messages["$key.string"] = "الإجابة في «{$label}» غير صالحة.";
                    break;

                case 'yes_no':
                    $rules[$key] = [$presence, 'string', Rule::in(['yes', 'no'])];
                    $messages["$key.in"] = "اختر نعم أو لا في «{$label}».";
                    $messages["$key.string"] = "الإجابة في «{$label}» غير صالحة.";
                    break;

                case 'consent':
                    // Ticking the box is the only answer there is: `yes`. Unticked is no answer at all.
                    $rules[$key] = [$presence, 'string', Rule::in(['yes'])];
                    $messages["$key.required"] = "يجب الموافقة على «{$label}» للمتابعة.";
                    $messages["$key.in"] = "الموافقة في «{$label}» غير صالحة.";
                    $messages["$key.string"] = "الموافقة في «{$label}» غير صالحة.";
                    break;

                case 'star_rating':
                    $stars = FormQuestionConfig::stars($question);
                    $rules[$key] = [$presence, 'integer', "between:1,{$stars}"];
                    $messages["$key.integer"] = "اختر تقييماً من 1 إلى {$stars} في «{$label}».";
                    $messages["$key.between"] = "اختر تقييماً من 1 إلى {$stars} في «{$label}».";
                    break;

                case 'linear_scale':
                    [$low, $high] = FormQuestionConfig::scaleBounds($question);
                    $rules[$key] = [$presence, 'integer', "between:{$low},{$high}"];
                    $messages["$key.integer"] = "اختر رقماً من {$low} إلى {$high} في «{$label}».";
                    $messages["$key.between"] = "اختر رقماً من {$low} إلى {$high} في «{$label}».";
                    break;

                case 'multiselect':
                case 'checkbox':
                    $allowed = $question->allowedOptions();
                    $maxChoices = min(max(1, count($allowed)), (int) ($question->setting('validation.max_choices') ?: PHP_INT_MAX));
                    $minChoices = (int) $question->setting('validation.min_choices', 0);
                    $rules[$key] = [$presence, 'array', 'max:'.$maxChoices];
                    if ($minChoices > 0) {
                        $rules[$key][] = function (string $attribute, mixed $value, Closure $fail) use ($minChoices, $label): void {
                            if (is_array($value) && $value !== [] && count($value) < $minChoices) {
                                $fail("اختر {$minChoices} من الخيارات على الأقل في «{$label}».");
                            }
                        };
                    }
                    $rules["$key.*"] = ['string', Rule::in($allowed)];
                    $messages["$key.array"] = "الإجابة في «{$label}» يجب أن تكون قائمة اختيارات.";
                    $messages["$key.max"] = "عدد الاختيارات في «{$label}» أكبر من المسموح ({$maxChoices}).";
                    $messages["$key.*.in"] = "اختر من الخيارات المتاحة فقط في «{$label}».";
                    $messages["$key.*.string"] = "الإجابة في «{$label}» غير صالحة.";
                    break;

                case 'file':
                case 'image':
                    $image = $question->type === 'image';
                    $rules[$key] = [$presence, 'file', 'max:'.FormAttachmentFile::MAX_KB, new FormAttachmentFile($image ? FormAttachmentFile::IMAGES : [])];
                    $messages["$key.file"] = $image ? "أرفق صورة صالحة في «{$label}»." : "أرفق ملفاً صالحاً في «{$label}».";
                    $messages["$key.max"] = 'حجم الملف في «'.$label.'» يتجاوز '.(FormAttachmentFile::MAX_KB / 1024).' ميغابايت.';
                    $messages["$key.uploaded"] = "تعذّر رفع الملف في «{$label}». حاول مرة أخرى.";
                    break;

                case 'textarea':
                case 'text':
                default:
                    $cap = $question->type === 'textarea' ? self::TEXTAREA_MAX : self::TEXT_MAX;
                    $maxLen = min($cap, (int) ($question->setting('validation.max_length') ?: $cap));
                    $minLen = (int) $question->setting('validation.min_length', 0);
                    $rules[$key] = [$presence, 'string', 'max:'.$maxLen];
                    if ($minLen > 0) {
                        $rules[$key][] = 'min:'.$minLen;
                        $messages["$key.min"] = "الإجابة في «{$label}» أقصر من المسموح (الحد الأدنى {$minLen} حرفاً).";
                    }
                    $messages["$key.string"] = "الإجابة في «{$label}» غير صالحة.";
                    $messages["$key.max"] = "الإجابة في «{$label}» أطول من المسموح (الحد الأقصى {$maxLen} حرفاً).";
            }
        }

        return [$rules, $messages];
    }

    /**
     * Submission metadata is an optional, small bag of flat facts (for example where the form was opened from).
     * Bounded on purpose: a handful of entries, short keys, short scalar values, a small total.
     */
    private function metadataRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $invalid = 'بيانات الإرسال الإضافية غير صالحة.';
            if (count($value) > self::METADATA_MAX_ENTRIES) {
                $fail($invalid);

                return;
            }

            foreach ($value as $k => $v) {
                $okKey = is_string($k) && preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $k) === 1;
                $okValue = is_bool($v) || is_int($v) || is_float($v) || (is_string($v) && mb_strlen($v) <= self::METADATA_VALUE_MAX);
                if (! $okKey || ! $okValue) {
                    $fail($invalid);

                    return;
                }
            }

            if (strlen((string) json_encode($value, JSON_UNESCAPED_UNICODE)) > self::METADATA_MAX_BYTES) {
                $fail($invalid);
            }
        };
    }
}
