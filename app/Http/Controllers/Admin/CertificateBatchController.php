<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\CertificateGenerationBatch;
use Illuminate\Http\Request;

class CertificateBatchController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $batches = CertificateGenerationBatch::with(['createdBy', 'template'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->certificate_type, fn ($q) => $q->where('certificate_type', $request->certificate_type))
            ->latest()
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data'    => $batches,
        ]);
    }

    public function show(CertificateGenerationBatch $batch): \Illuminate\Http\JsonResponse
    {
        $batch->load(['createdBy', 'template']);

        $certificates = $batch->certificates()
            ->with(['user', 'course', 'track', 'workshop'])
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => [
                'batch'        => $batch,
                'certificates' => CertificateResource::collection($certificates)->response()->getData(true),
            ],
        ]);
    }
}
