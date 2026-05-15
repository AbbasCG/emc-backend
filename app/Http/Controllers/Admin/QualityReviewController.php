<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\QualityReviewResource;
use App\Models\QualityReview;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class QualityReviewController extends Controller
{
    private array $scoreRules = [
        'overall_score'           => 'required|numeric|min:0|max:10',
        'objective_clarity_score' => 'nullable|numeric|min:0|max:10',
        'content_quality_score'   => 'nullable|numeric|min:0|max:10',
        'instructor_score'        => 'nullable|numeric|min:0|max:10',
        'organization_score'      => 'nullable|numeric|min:0|max:10',
        'time_commitment_score'   => 'nullable|numeric|min:0|max:10',
        'completion_score'        => 'nullable|numeric|min:0|max:10',
        'output_quality_score'    => 'nullable|numeric|min:0|max:10',
        'repeatability_score'     => 'nullable|numeric|min:0|max:10',
    ];

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $reviews = QualityReview::with('reviewer')
            ->when($request->reviewable_type, fn ($q) => $q->where('reviewable_type', $request->reviewable_type))
            ->when($request->reviewable_id, fn ($q) => $q->where('reviewable_id', $request->reviewable_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => QualityReviewResource::collection($reviews)->response()->getData(true)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(array_merge([
            'reviewable_type'  => 'required|string',
            'reviewable_id'    => 'required|integer',
            'notes'            => 'nullable|string',
            'recommendations'  => 'nullable|string',
            'status'           => 'in:draft,submitted',
        ], $this->scoreRules));

        $data['reviewer_id']  = $request->user()->id;
        $data['reviewed_at']  = $data['status'] === 'submitted' ? now() : null;

        $review = QualityReview::create($data);

        ActivityLogger::log('quality_review.created', $review, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new QualityReviewResource($review->load('reviewer')),
        ], 201);
    }

    public function show(QualityReview $qualityReview): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new QualityReviewResource($qualityReview->load('reviewer')),
        ]);
    }

    public function update(Request $request, QualityReview $qualityReview): \Illuminate\Http\JsonResponse
    {
        if ($qualityReview->status === 'approved') {
            return response()->json(['success' => false, 'message' => 'Approved reviews cannot be edited.'], 422);
        }

        $data = $request->validate(array_merge([
            'notes'           => 'nullable|string',
            'recommendations' => 'nullable|string',
            'status'          => 'in:draft,submitted',
        ], array_map(fn ($r) => str_replace('required|', 'nullable|', $r), $this->scoreRules)));

        if (isset($data['status']) && $data['status'] === 'submitted') {
            $data['reviewed_at'] = now();
        }

        $qualityReview->update($data);

        return response()->json([
            'success' => true,
            'data'    => new QualityReviewResource($qualityReview->load('reviewer')),
        ]);
    }

    public function approve(Request $request, QualityReview $qualityReview): \Illuminate\Http\JsonResponse
    {
        if ($qualityReview->status !== 'submitted') {
            return response()->json(['success' => false, 'message' => 'Only submitted reviews can be approved.'], 422);
        }

        $qualityReview->update(['status' => 'approved']);

        ActivityLogger::log('quality_review.approved', $qualityReview, [], $request);

        return response()->json([
            'success' => true,
            'message' => 'Quality review approved.',
            'data'    => new QualityReviewResource($qualityReview),
        ]);
    }
}
