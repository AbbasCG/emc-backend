<?php

namespace App\Http\Requests;

use App\Services\CompressedUploadService;
use Illuminate\Foundation\Http\FormRequest;

class UploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $context = $this->route('context');

        return $this->user() !== null
            && $context !== null
            && array_key_exists($context, CompressedUploadService::CONTEXTS);
    }

    public function rules(): array
    {
        $context = (string) $this->route('context');
        $types   = CompressedUploadService::CONTEXTS[$context]['types'] ?? ['image'];

        $hasImage = in_array('image', $types, true);
        $hasPdf   = in_array('pdf', $types, true);

        if ($hasImage && $hasPdf) {
            return ['file' => ['required', 'file', $this->mixedRule()]];
        }

        if ($hasPdf) {
            // max is in KB for Laravel's validation
            return ['file' => ['required', 'file', 'mimes:pdf', 'max:' . (CompressedUploadService::PDF_MAX / 1024)]];
        }

        return ['file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . (CompressedUploadService::IMAGE_MAX / 1024)]];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'A file is required.',
            'file.image'    => 'Only image files are accepted (jpg, jpeg, png, webp).',
            'file.mimes'    => 'File type not supported for this context.',
            'file.max'      => 'File exceeds the size limit for this context.',
        ];
    }

    private function mixedRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $mime    = $value->getMimeType();
            $size    = $value->getSize();
            $allowed = [...CompressedUploadService::IMAGE_MIMES, CompressedUploadService::PDF_MIME];

            if (! in_array($mime, $allowed, true)) {
                $fail('Only jpg, jpeg, png, webp, and pdf files are accepted.');
                return;
            }

            if (in_array($mime, CompressedUploadService::IMAGE_MIMES, true) && $size > CompressedUploadService::IMAGE_MAX) {
                $fail('Image files must not exceed 5 MB.');
                return;
            }

            if ($mime === CompressedUploadService::PDF_MIME && $size > CompressedUploadService::PDF_MAX) {
                $fail('PDF files must not exceed 15 MB.');
            }
        };
    }
}
