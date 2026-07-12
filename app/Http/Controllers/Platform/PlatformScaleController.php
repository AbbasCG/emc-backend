<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformScaleService;
use Illuminate\Http\JsonResponse;

class PlatformScaleController extends Controller
{
    public function __construct(private PlatformScaleService $service) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->service->snapshot(),
        ]);
    }
}
