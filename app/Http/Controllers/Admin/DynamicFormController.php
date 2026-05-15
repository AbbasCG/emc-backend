<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DynamicFormResource;
use App\Http\Resources\FormQuestionResource;
use App\Http\Resources\FormSubmissionResource;
use App\Models\DynamicForm;
use App\Models\FormQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DynamicFormController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $forms = DynamicForm::with('department')
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => DynamicFormResource::collection($forms)->response()->getData(true)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'slug'          => 'nullable|string|unique:dynamic_forms,slug',
            'description'   => 'nullable|string',
            'type'          => 'nullable|string|max:100',
            'department_id' => 'nullable|exists:departments,id',
            'related_type'  => 'nullable|string',
            'related_id'    => 'nullable|integer',
            'status'        => 'in:draft,active,closed,archived',
        ]);

        $data['slug']       = $data['slug'] ?? Str::slug($data['title']) . '-' . Str::random(5);
        $data['created_by'] = $request->user()->id;

        $form = DynamicForm::create($data);

        return response()->json([
            'success' => true,
            'data'    => new DynamicFormResource($form->load('department')),
        ], 201);
    }

    public function show(DynamicForm $form): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new DynamicFormResource($form->load(['department', 'questions'])),
        ]);
    }

    public function update(Request $request, DynamicForm $form): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'         => 'string|max:255',
            'slug'          => 'string|unique:dynamic_forms,slug,' . $form->id,
            'description'   => 'nullable|string',
            'type'          => 'nullable|string|max:100',
            'department_id' => 'nullable|exists:departments,id',
            'status'        => 'in:draft,active,closed,archived',
        ]);

        $form->update($data);

        return response()->json([
            'success' => true,
            'data'    => new DynamicFormResource($form->load(['department', 'questions'])),
        ]);
    }

    public function destroy(DynamicForm $form): \Illuminate\Http\JsonResponse
    {
        $form->delete();

        return response()->json(['success' => true, 'message' => 'Form deleted.']);
    }

    // ── Questions ──────────────────────────────────────────────────────────────

    public function indexQuestions(DynamicForm $form): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => FormQuestionResource::collection($form->questions),
        ]);
    }

    public function storeQuestion(Request $request, DynamicForm $form): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'label'       => 'required|string|max:255',
            'type'        => 'required|in:text,textarea,email,phone,number,select,multiselect,radio,checkbox,date,file',
            'options'     => 'nullable|array',
            'is_required' => 'boolean',
            'sort_order'  => 'integer',
        ]);

        $data['form_id'] = $form->id;
        $question = FormQuestion::create($data);

        return response()->json([
            'success' => true,
            'data'    => new FormQuestionResource($question),
        ], 201);
    }

    public function updateQuestion(Request $request, DynamicForm $form, FormQuestion $question): \Illuminate\Http\JsonResponse
    {
        abort_if($question->form_id !== $form->id, 404);

        $data = $request->validate([
            'label'       => 'string|max:255',
            'type'        => 'in:text,textarea,email,phone,number,select,multiselect,radio,checkbox,date,file',
            'options'     => 'nullable|array',
            'is_required' => 'boolean',
            'sort_order'  => 'integer',
        ]);

        $question->update($data);

        return response()->json([
            'success' => true,
            'data'    => new FormQuestionResource($question),
        ]);
    }

    public function destroyQuestion(DynamicForm $form, FormQuestion $question): \Illuminate\Http\JsonResponse
    {
        abort_if($question->form_id !== $form->id, 404);
        $question->delete();

        return response()->json(['success' => true, 'message' => 'Question deleted.']);
    }

    // ── Submissions ────────────────────────────────────────────────────────────

    public function submissions(Request $request, DynamicForm $form): \Illuminate\Http\JsonResponse
    {
        $submissions = $form->submissions()
            ->with(['user', 'answers.question'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest('submitted_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => FormSubmissionResource::collection($submissions)->response()->getData(true),
        ]);
    }

    public function updateSubmissionStatus(Request $request, DynamicForm $form, \App\Models\FormSubmission $submission): \Illuminate\Http\JsonResponse
    {
        abort_if($submission->form_id !== $form->id, 404);

        $data = $request->validate(['status' => 'required|in:new,reviewed,archived']);
        $submission->update($data);

        return response()->json(['success' => true, 'message' => 'Status updated.']);
    }
}
