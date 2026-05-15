<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function __construct(private readonly CertificateService $certificateService) {}

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $certificates = Certificate::with(['user', 'course', 'track', 'workshop', 'approvedBy'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->certificate_type, fn ($q) => $q->where('certificate_type', $request->certificate_type))
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->latest()
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data'    => CertificateResource::collection($certificates)->response()->getData(true),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'user_id'            => 'required|exists:users,id',
            'course_id'          => 'nullable|exists:courses,id',
            'track_id'           => 'nullable|exists:tracks,id',
            'workshop_id'        => 'nullable|exists:workshops,id',
            'certificate_type'   => 'required|in:workshop_attendance,course_completion,track_completion,professional_certificate',
            'title'              => 'required|string|max:255',
            'professional_title' => 'nullable|string|max:255',
            'expires_at'         => 'nullable|date',
            'metadata'           => 'nullable|array',
        ]);

        $data['verification_code'] = $this->certificateService->generateVerificationCode();

        $certificate = Certificate::create($data);

        return response()->json([
            'success' => true,
            'data'    => new CertificateResource($certificate->load(['user', 'course', 'track', 'workshop'])),
        ], 201);
    }

    public function show(Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new CertificateResource($certificate->load(['user', 'course', 'track', 'workshop', 'approvedBy'])),
        ]);
    }

    public function approve(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        if ($certificate->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Only pending certificates can be approved.'], 422);
        }

        $certificate = $this->certificateService->approve($certificate, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Certificate approved.',
            'data'    => new CertificateResource($certificate->load(['user', 'approvedBy'])),
        ]);
    }

    public function issue(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        if (! in_array($certificate->status, ['pending', 'approved'])) {
            return response()->json(['success' => false, 'message' => 'Certificate cannot be issued in its current state.'], 422);
        }

        $certificate = $this->certificateService->issue($certificate, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Certificate issued.',
            'data'    => new CertificateResource($certificate->load(['user', 'approvedBy'])),
        ]);
    }

    public function revoke(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        if ($certificate->status === 'revoked') {
            return response()->json(['success' => false, 'message' => 'Certificate is already revoked.'], 422);
        }

        $certificate = $this->certificateService->revoke($certificate, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Certificate revoked.',
            'data'    => new CertificateResource($certificate),
        ]);
    }
}
