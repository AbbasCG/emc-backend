<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use Illuminate\Http\Request;

class StudentCertificateController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $certificates = Certificate::with(['course', 'track', 'workshop'])
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['issued', 'approved'])
            ->latest('issued_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => CertificateResource::collection($certificates),
        ]);
    }
}
