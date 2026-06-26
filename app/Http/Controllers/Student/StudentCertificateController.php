<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentCertificateController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $certificates = Certificate::with(['course', 'track', 'workshop', 'template'])
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['issued', 'pending_generation', 'approved'])
            ->latest('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => CertificateResource::collection($certificates),
        ]);
    }

    public function show(Request $request, Certificate $certificate): \Illuminate\Http\JsonResponse
    {
        if ($certificate->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
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
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        if ($certificate->status !== 'issued') {
            return response()->json(['success' => false, 'message' => 'Certificate is not yet issued.'], 403);
        }

        if (! $certificate->pdf_path || ! Storage::disk('local')->exists($certificate->pdf_path)) {
            return response()->json(['success' => false, 'message' => 'Certificate file not found.'], 404);
        }

        $content = Storage::disk('local')->get($certificate->pdf_path);

        return response($content, 200, [
            'Content-Type'        => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="certificate-' . ($certificate->certificate_code ?? $certificate->id) . '.html"',
        ]);
    }
}
