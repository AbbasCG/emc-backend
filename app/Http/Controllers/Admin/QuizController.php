<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuizResource;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $quizzes = Quiz::withCount('questions')
            ->when($request->course_id, fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->lesson_id, fn ($q) => $q->where('lesson_id', $request->lesson_id))
            ->get();

        return response()->json(['success' => true, 'data' => QuizResource::collection($quizzes)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'course_id'     => 'nullable|exists:courses,id',
            'lesson_id'     => 'nullable|exists:lessons,id',
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string',
            'passing_score' => 'nullable|numeric|min:0|max:100',
            'status'        => 'in:active,inactive,draft',
        ]);

        $quiz = Quiz::create($data);

        return response()->json(['success' => true, 'data' => new QuizResource($quiz)], 201);
    }

    public function show(Quiz $quiz): \Illuminate\Http\JsonResponse
    {
        // Admin sees correct answers
        $questions = $quiz->questions()->get()->each->makeVisibleForAdmin();

        return response()->json([
            'success' => true,
            'data'    => array_merge((new QuizResource($quiz))->toArray(request()), [
                'questions' => $questions->map(fn ($q) => array_merge(
                    $q->toArray(), ['correct_answer' => $q->correct_answer]
                )),
            ]),
        ]);
    }

    public function update(Request $request, Quiz $quiz): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'         => 'string|max:255',
            'description'   => 'nullable|string',
            'passing_score' => 'nullable|numeric|min:0|max:100',
            'status'        => 'in:active,inactive,draft',
        ]);

        $quiz->update($data);

        return response()->json(['success' => true, 'data' => new QuizResource($quiz)]);
    }

    public function destroy(Quiz $quiz): \Illuminate\Http\JsonResponse
    {
        $quiz->delete();

        return response()->json(['success' => true, 'message' => 'Quiz deleted.']);
    }

    public function storeQuestion(Request $request, Quiz $quiz): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'question'       => 'required|string',
            'type'           => 'required|in:multiple_choice,true_false,short_answer,multi_select',
            'options'        => 'nullable|array',
            'correct_answer' => 'nullable',
            'points'         => 'integer|min:1',
            'sort_order'     => 'integer',
        ]);

        $data['correct_answer'] = is_array($data['correct_answer'] ?? null)
            ? $data['correct_answer']
            : [$data['correct_answer'] ?? null];

        $question = $quiz->questions()->create($data);

        return response()->json([
            'success' => true,
            'data'    => array_merge($question->toArray(), ['correct_answer' => $question->correct_answer]),
        ], 201);
    }
}
