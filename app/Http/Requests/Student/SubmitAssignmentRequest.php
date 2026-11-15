<?php

namespace App\Http\Requests\Student;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\User;
use App\Services\AssignmentUploads\AssignmentSubmissionPreconditions;
use App\Services\AssignmentUploads\AssignmentUploadPolicy;
use App\Services\AssignmentUploads\InspectedUpload;
use App\Services\AssignmentUploads\UploadedFileInspector;
use App\Services\AssignmentUploads\UploadRejectedException;
use App\Services\StudentAssignmentSubmissionGate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/student/assignments/{assignment}/submit
 *
 * Fields: text_answer | answer_text, notes, files[] (up to the configured maximum) and the legacy single `file`
 * (old clients). EVERY file is verified here, before the controller stores anything, so one bad file means a 422
 * with nothing written and the assignment not marked submitted.
 *
 * Authorization (course enrollment + class group) runs first, exactly as the inline gate used to: an account that may
 * not submit still gets 403 regardless of payload validity.
 */
class SubmitAssignmentRequest extends FormRequest
{
    /** @var list<InspectedUpload> */
    private array $inspected = [];

    public function authorize(): bool
    {
        $assignment = $this->route('assignment');
        $user = $this->user();

        return $assignment instanceof Assignment
            && $user instanceof User
            && app(StudentAssignmentSubmissionGate::class)->allows($user, $assignment);
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'غير مصرح لك بالوصول إلى هذا المسار.',
        ], 403));
    }

    public function rules(): array
    {
        return [
            'text_answer' => ['nullable', 'string', 'max:20000'],
            'answer_text' => ['nullable', 'string', 'max:20000'],
            'notes'       => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'text_answer.string' => 'الإجابة النصية غير صالحة.',
            'text_answer.max'    => 'الإجابة النصية طويلة جداً. الحد الأقصى :max حرف.',
            'answer_text.string' => 'الإجابة النصية غير صالحة.',
            'answer_text.max'    => 'الإجابة النصية طويلة جداً. الحد الأقصى :max حرف.',
            'notes.string'       => 'الملاحظات غير صالحة.',
            'notes.max'          => 'الملاحظات طويلة جداً. الحد الأقصى :max حرف.',
        ];
    }

    public function attributes(): array
    {
        return [
            'text_answer' => 'الإجابة النصية',
            'answer_text' => 'الإجابة النصية',
            'notes'       => 'الملاحظات',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateFiles($validator);
            },
        ];
    }

    /**
     * Files that passed every check, deduplicated by content, in upload order. Empty before validation ran.
     *
     * @return list<InspectedUpload>
     */
    public function inspectedUploads(): array
    {
        return $this->inspected;
    }

    /** Every uploaded file in request order: files[] first, then the legacy `file`. @return array<string,UploadedFile> keyed by error key */
    private function collectUploads(): array
    {
        $out = [];

        $files = $this->file('files');
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }
        foreach ((array) $files as $i => $file) {
            if ($file instanceof UploadedFile) {
                $out['files.'.$i] = $file;
            }
        }

        $legacy = $this->file('file');
        if ($legacy instanceof UploadedFile) {
            $out['file'] = $legacy;
        }

        return $out;
    }

    private function validateFiles(Validator $validator): void
    {
        $policy = app(AssignmentUploadPolicy::class);
        $inspector = app(UploadedFileInspector::class);

        // A non-file value in a file field (e.g. `files=abc`) is a client bug, not a silently dropped upload.
        foreach (['files', 'file'] as $field) {
            if ($this->has($field) && ! $this->hasFile($field)) {
                $value = $this->input($field);
                if ($value !== null && $value !== '' && $value !== []) {
                    $validator->errors()->add($field, 'تعذّر قراءة الملفات المرفوعة. أعد اختيار الملفات وحاول مرة أخرى.');
                }
            }
        }

        $uploads = $this->collectUploads();
        $max = $policy->maxFiles();

        // Cheap business rules FIRST (already submitted / attempts used up / nothing to submit): refusing those must not
        // cost reading, hashing and zip-listing up to 25 MB.
        $assignment = $this->route('assignment');
        if ($assignment instanceof Assignment && $this->user()) {
            $existing = AssignmentSubmission::query()
                ->where('assignment_id', $assignment->id)
                ->where('user_id', $this->user()->id)
                ->first();
            $violations = app(AssignmentSubmissionPreconditions::class)->violations(
                $assignment,
                $existing,
                filled($this->input('text_answer') ?? $this->input('answer_text')),
                $uploads !== [],
            );
            if ($violations !== []) {
                foreach ($violations as $key => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($key, $message);
                    }
                }

                return;
            }
        }

        if (count($uploads) > $max) {
            $validator->errors()->add('files', "عدد الملفات أكبر من المسموح. الحد الأقصى {$max} ملفات في التسليم الواحد.");

            return; // do not spend time inspecting a request that is rejected anyway
        }

        // Field-level validation (answer too long, notes too long, a non-file value in a file field) already failed:
        // the request is rejected anyway, so do not read / hash / zip-list up to 25 MB.
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $accepted = [];
        $total = 0;
        $rejections = [];
        foreach ($uploads as $key => $file) {
            try {
                $inspected = $inspector->inspect($file);
            } catch (UploadRejectedException $e) {
                $rejections[$e->reason] = ($rejections[$e->reason] ?? 0) + 1;
                // Prefix the (already sanitised) client file name so the user knows WHICH file failed. Upload-error
                // messages already name the file.
                $name = UploadedFileInspector::sanitizeDisplayName($file->getClientOriginalName());
                $message = str_contains($e->getMessage(), "«{$name}»") ? $e->getMessage() : "«{$name}»: ".$e->getMessage();
                $validator->errors()->add($key, $message);

                continue;
            }

            $accepted[] = $inspected;
            $total += $inspected->size;
        }

        $this->logRejections($rejections);

        if ($total > $policy->maxTotalBytes()) {
            $validator->errors()->add(
                'files',
                'الحجم الإجمالي للملفات أكبر من المسموح. الحد الأقصى '.AssignmentUploadPolicy::formatBytesAr($policy->maxTotalBytes()).' لجميع الملفات معاً.',
            );
        }

        $this->inspected = $accepted;
    }

    /** ONE log line per request, however many files were refused (a flood of bad uploads must not flood the log). */
    private function logRejections(array $rejections): void
    {
        if ($rejections === []) {
            return;
        }

        $transfer = array_filter(array_keys($rejections), fn ($r) => str_starts_with($r, 'upload_error_')) !== [];

        Log::warning(
            $transfer
                ? 'Assignment upload failed before reaching the validator (PHP/web-server limit or interrupted upload).'
                : 'Assignment upload refused.',
            [
                'reasons'             => $rejections,
                'user_id'             => $this->user()?->id,
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size'       => (string) ini_get('post_max_size'),
                'max_file_uploads'    => (string) ini_get('max_file_uploads'),
            ],
        );
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(AssignmentSubmissionPreconditions::rejection($validator->errors()->messages()));
    }
}
