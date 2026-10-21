<?php

namespace App\Http\Requests;

use App\Models\DynamicForm;
use App\Models\FormQuestion;
use App\Services\Forms\FormQuestionSyncService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update of a form's DRAFT, optionally with its whole block list.
 *
 * The builder speaks `form_type` and `required`; the stored columns are `type` and `is_required`. Both spellings are
 * accepted and folded into the stored one here, so the contract no longer depends on which client sent the request.
 * What each question may carry (limits, scale, default, options) is checked per type by FormQuestionConfig, through
 * FormQuestionSyncService::normalizeAll, so the same rules apply to a save from the builder and to any other client.
 * Authorization is the controller's job (DynamicFormPolicy), not this class's.
 *
 * `base_revision` is the draft revision the editor started from; the controller refuses the save if the draft has moved on
 * (two editors), unless `force` says the editor chose to overwrite.
 */
class SaveDynamicFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('type') && $this->has('form_type')) {
            $this->merge(['type' => $this->input('form_type')]);
        }

        $questions = $this->input('questions');
        if (is_array($questions)) {
            $this->merge(['questions' => array_map(function ($q) {
                if (is_array($q) && ! array_key_exists('is_required', $q) && array_key_exists('required', $q)) {
                    $q['is_required'] = $q['required'];
                }

                return $q;
            }, $questions)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $form = $this->route('form');
        $formId = $form instanceof DynamicForm ? $form->id : null;

        return [
            'title'         => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            // Unique on update (a form cannot take another form's public link). On create a taken slug is made
            // unique by the controller instead, so two forms with the same title never fail on a link nobody typed.
            'slug'          => ['nullable', 'string', 'max:150', 'regex:/^[\p{L}\p{M}\p{N}_-]+$/u', ...($creating ? [] : [Rule::unique('dynamic_forms', 'slug')->ignore($formId)])],
            'description'   => ['nullable', 'string', 'max:5000'],
            'type'          => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'related_type'  => ['nullable', 'string', 'max:100'],
            'related_id'    => ['nullable', 'integer'],
            'status'        => ['sometimes', Rule::in(DynamicForm::STATUSES)],
            // Only honoured when a form is created; afterwards visibility is changed through the settings endpoint,
            // which only the owner (or a platform-wide admin) may use.
            'visibility'           => ['sometimes', Rule::in(DynamicForm::VISIBILITIES)],
            'responses_visibility' => ['sometimes', Rule::in(DynamicForm::RESPONSE_VISIBILITIES)],

            'base_revision' => ['nullable', 'integer', 'min:0'],
            'force'         => ['sometimes', 'boolean'],

            'questions'               => ['sometimes', 'array', 'max:200'],
            'questions.*'             => ['array'],
            'questions.*.id'          => ['nullable', 'integer'],
            // The editor's temporary id for an unsaved block that a visibility rule reads. Always negative: a stored id is sent as `id`,
            // so a `ref` can never be mistaken for (or collide with) the id of a stored block.
            'questions.*.ref'         => ['nullable', 'integer', 'max:-1'],
            // The text is required per type (a page break has none): FormQuestionConfig decides, with a message per field.
            'questions.*.label'       => ['nullable', 'string', 'max:255'],
            'questions.*.type'        => ['required', Rule::in(FormQuestion::TYPES)],
            'questions.*.options'     => ['nullable', 'array', 'max:100'],
            'questions.*.options.*'   => ['nullable', 'string', 'max:255'],
            'questions.*.is_required' => ['sometimes', 'boolean'],
            'questions.*.description' => ['nullable', 'string', 'max:2000'],
            'questions.*.placeholder' => ['nullable', 'string', 'max:255'],
            'questions.*.help_text'   => ['nullable', 'string', 'max:500'],
            'questions.*.config'      => ['nullable', 'array'],
            'questions.*.logic'       => ['nullable', 'array'],
            // Steps and the review step. Unknown keys are ignored.
            'flow'                    => ['nullable', 'array'],
            'flow.review_step'        => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required'              => 'عنوان النموذج مطلوب.',
            'slug.unique'                 => 'رابط النموذج مستخدم في نموذج آخر.',
            'slug.regex'                  => 'رابط النموذج يقبل الحروف والأرقام والشرطات فقط.',
            'status.in'                   => 'حالة النموذج غير صالحة.',
            'department_id.exists'        => 'القسم المحدد غير موجود.',
            'questions.max'               => 'عدد العناصر يتجاوز الحد المسموح (200).',
            'questions.*.type.required'   => 'نوع السؤال مطلوب.',
            'questions.*.type.in'         => 'نوع السؤال غير مدعوم.',
        ];
    }

    /**
     * Laravel fills the validated array rule by rule, so an item without an `id` (a block added in the middle of the list) is
     * written after the items that have one. Position IS the order of the blocks, so the list is put back in the order it was
     * sent before anything reads it.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        if (isset($data['questions']) && is_array($data['questions'])) {
            ksort($data['questions'], SORT_NUMERIC);
            $data['questions'] = array_values($data['questions']);
        }

        return $key === null ? $data : data_get($data, $key, $default);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $questions = $this->input('questions');
            if (! is_array($questions)) {
                return;
            }

            // Per-type checks (limits, scale, default, options). Items of an unknown type are reported by the type rule above.
            foreach (FormQuestionSyncService::errorsFor(array_values(array_filter($questions, 'is_array'))) as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        }];
    }
}
