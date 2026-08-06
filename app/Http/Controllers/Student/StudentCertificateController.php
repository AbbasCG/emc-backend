<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Services\ActivityLogger;
use App\Services\Certificate\CertificateRenderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentCertificateController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $userId = $request->user()->id;
        $perPage = min((int) ($request->input('per_page', $request->input('perPage', 20))), 100);

        $query = Certificate::with(['course', 'track', 'workshop', 'template'])
            ->where('user_id', $userId)
            ->where('status', 'issued')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where(function ($q2) use ($term) {
                    $q2->where('title', 'like', "%{$term}%")
                        ->orWhere('certificate_code', 'like', "%{$term}%")
                        ->orWhereHas('course', fn ($c) => $c->where('title', 'like', "%{$term}%"))
                        ->orWhereHas('workshop', fn ($w) => $w->where('title', 'like', "%{$term}%"));
                });
            })
            ->when($request->filled('certificate_type'), fn ($q) => $q->where('certificate_type', $request->input('certificate_type')))
            ->when($request->filled('issued_from'), fn ($q) => $q->whereDate('issued_at', '>=', $request->input('issued_from')))
            ->when($request->filled('issued_to'), fn ($q) => $q->whereDate('issued_at', '<=', $request->input('issued_to')));

        $sortBy  = in_array($request->input('sort_by'), ['issued_at', 'created_at', 'title']) ? $request->input('sort_by') : 'created_at';
        $sortDir = $request->input('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $certificates = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => CertificateResource::collection($certificates),
            'meta'    => [
                'total'        => $certificates->total(),
                'per_page'     => $certificates->perPage(),
                'current_page' => $certificates->currentPage(),
                'last_page'    => $certificates->lastPage(),
                'from'         => $certificates->firstItem(),
                'to'           => $certificates->lastItem(),
            ],
        ]);
    }

    public function show(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        if ($certificate->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'غير مصرح.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => new CertificateResource(
                $certificate->load(['course', 'track', 'workshop', 'template', 'issuedBy'])
            ),
        ]);
    }

    public function download(Request $request, Certificate $certificate): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        if ($certificate->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'غير مصرح.'], 403);
        }

        if ($certificate->status !== 'issued') {
            return response()->json(['success' => false, 'message' => 'الشهادة لم تصدر بعد.'], 403);
        }

        try {
            $response = app(CertificateRenderService::class)->getDownloadResponse($certificate);

            ActivityLogger::log('certificate.downloaded', $certificate, [], $request, null, [
                'certificate_code' => $certificate->certificate_code,
                'template_id'      => $certificate->certificate_template_id,
                'role'             => 'student',
            ]);

            Log::info('[Certificate] Student downloaded certificate', [
                'certificate_id' => $certificate->id,
                'user_id'        => $request->user()->id,
                'ip'             => $request->ip(),
                'template_id'    => $certificate->certificate_template_id,
            ]);

            return $response;
        } catch (\Throwable $e) {
            Log::error('[Certificate] Student download failed', [
                'certificate_id' => $certificate->id,
                'user_id'        => $request->user()->id,
                'ip'             => $request->ip(),
                'error'          => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'تعذّر إنشاء ملف الشهادة. حاول لاحقاً.',
            ], 500);
        }
    }
}
