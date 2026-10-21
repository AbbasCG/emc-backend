<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DynamicForm;
use App\Models\FormExport;
use App\Services\Forms\FormExportService;
use App\Services\Forms\FormResponseQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporting the responses of a form to XLSX, CSV or PDF.
 *
 * There is no export permission. Asking for an export, reading its status and downloading its file all require the right to READ
 * the form's responses (viewResponses) at that moment, and a file is only ever downloadable by the person who asked for it, so
 * withdrawing someone's access also withdraws their files. The rows are exactly the filters' rows (FormResponseQuery) and the
 * columns are the ones that person is shown.
 */
class FormExportController extends Controller
{
    private const FILTER_KEYS = ['search', 'status', 'assignee', 'version', 'from', 'to', 'source', 'corrected'];

    public function __construct(private readonly FormExportService $exports) {}

    public function store(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('viewResponses', $form);

        $rules = ['format' => ['required', Rule::in(FormExport::FORMATS)], 'columns' => ['nullable', 'array', 'max:200'], 'columns.*' => ['string', 'max:40'], 'filters' => ['nullable', 'array']];
        foreach (FormResponseQuery::rules() as $key => $rule) {
            $rules['filters.'.$key] = $rule;
        }
        $data = $request->validate($rules, ['format.required' => 'اختر صيغة التصدير.', 'format.in' => 'صيغة التصدير غير مدعومة. اختر XLSX أو CSV أو PDF.']);

        $filters = FormResponseQuery::normalize(array_intersect_key((array) ($data['filters'] ?? []), array_flip(self::FILTER_KEYS)), $request->user());
        $export = $this->exports->request($form, $request->user(), $data['format'], $filters, $data['columns'] ?? null, $request);

        return response()->json(['success' => true, 'data' => $this->present($export)], 201);
    }

    public function show(Request $request, DynamicForm $form, FormExport $export): JsonResponse
    {
        $this->own($request, $form, $export);

        return response()->json(['success' => true, 'data' => $this->present($export)]);
    }

    public function download(Request $request, DynamicForm $form, FormExport $export): StreamedResponse|JsonResponse
    {
        $this->own($request, $form, $export);

        if ($export->status === FormExport::EXPIRED || ($export->status === FormExport::READY && ! $export->isDownloadable())) {
            return response()->json(['success' => false, 'message' => 'انتهت صلاحية ملف التصدير. أنشئ تصديراً جديداً.'], 410);
        }
        if (! $export->isDownloadable() || ! Storage::disk(FormExportService::DISK)->exists((string) $export->file_path)) {
            return response()->json(['success' => false, 'message' => 'ملف التصدير غير جاهز بعد.'], 409);
        }

        return Storage::disk(FormExportService::DISK)->download((string) $export->file_path, (string) $export->file_name, [
            'Content-Type'           => match ($export->format) {
                FormExport::CSV  => 'text/csv; charset=UTF-8',
                FormExport::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                default          => 'application/pdf',
            },
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
    }

    /** The export is of THIS form and of THIS person, and they may still read the responses; anything else is not found or refused. */
    private function own(Request $request, DynamicForm $form, FormExport $export): void
    {
        abort_if((int) $export->form_id !== (int) $form->id || (int) $export->user_id !== (int) $request->user()->id, 404);
        $this->authorize('viewResponses', $form);
    }

    /** @return array<string, mixed> */
    private function present(FormExport $export): array
    {
        return [
            'id'           => $export->id,
            'format'       => $export->format,
            'status'       => $export->status,
            'ready'        => $export->isDownloadable(),
            'row_count'    => $export->row_count,
            'file_name'    => $export->file_name,
            'file_size'    => $export->file_size,
            'error'        => $export->error,
            'created_at'   => $export->created_at,
            'completed_at' => $export->completed_at,
            'expires_at'   => $export->expires_at,
        ];
    }
}
