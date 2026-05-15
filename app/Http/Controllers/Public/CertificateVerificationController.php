<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;

class CertificateVerificationController extends Controller
{
    public function verify(string $code): \Illuminate\Http\JsonResponse
    {
        $certificate = Certificate::with(['user', 'course', 'track', 'workshop'])
            ->where('verification_code', strtoupper($code))
            ->first();

        if (! $certificate) {
            return response()->json([
                'success' => false,
                'message' => 'Certificate not found. The verification code may be invalid.',
            ], 404);
        }

        if ($certificate->status === 'revoked') {
            return response()->json([
                'success' => false,
                'message' => 'This certificate has been revoked.',
                'data'    => ['status' => 'revoked'],
            ], 410);
        }

        if ($certificate->expires_at && now()->gt($certificate->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'This certificate has expired.',
                'data'    => new CertificateResource($certificate),
            ], 410);
        }

        return response()->json([
            'success' => true,
            'message' => 'Certificate is valid.',
            'data'    => new CertificateResource($certificate),
        ]);
    }
}
