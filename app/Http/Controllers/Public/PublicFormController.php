<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\DynamicFormResource;
use App\Http\Resources\FormSubmissionResource;
use App\Models\DynamicForm;
use App\Models\FormAnswer;
use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicFormController extends Controller
{
    public function show(string $slug): \Illuminate\Http\JsonResponse
    {
        $form = DynamicForm::with('questions')
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data'    => new DynamicFormResource($form),
        ]);
    }

    public function submit(Request $request, string $slug): \Illuminate\Http\JsonResponse
    {
        $form = DynamicForm::with('questions')
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        // Build dynamic validation: required questions must have an answer
        $rules = [];
        foreach ($form->questions as $question) {
            $key = 'answers.' . $question->id;
            $ruleSet = $question->is_required ? ['required'] : ['nullable'];

            if ($question->type === 'email') {
                $ruleSet[] = 'email';
            } elseif ($question->type === 'file') {
                $ruleSet = $question->is_required ? ['required', 'file'] : ['nullable', 'file'];
            }

            $rules[$key] = $ruleSet;
        }

        $validated = $request->validate($rules);

        $submission = DB::transaction(function () use ($form, $request, $validated) {
            $submission = FormSubmission::create([
                'form_id'      => $form->id,
                'user_id'      => $request->user()?->id,
                'status'       => 'new',
                'submitted_at' => now(),
                'metadata'     => $request->metadata ?? null,
            ]);

            foreach ($form->questions as $question) {
                $answerValue = $validated['answers'][$question->id] ?? null;

                if ($question->type === 'file' && $request->hasFile("answers.{$question->id}")) {
                    $path = $request->file("answers.{$question->id}")->store('form-uploads', 'public');
                    FormAnswer::create([
                        'submission_id' => $submission->id,
                        'question_id'   => $question->id,
                        'file_path'     => $path,
                    ]);
                } else {
                    FormAnswer::create([
                        'submission_id' => $submission->id,
                        'question_id'   => $question->id,
                        'value'         => is_array($answerValue) ? json_encode($answerValue) : $answerValue,
                    ]);
                }
            }

            return $submission;
        });

        return response()->json([
            'success' => true,
            'message' => 'Form submitted successfully.',
            'data'    => ['submission_id' => $submission->id],
        ], 201);
    }
}
