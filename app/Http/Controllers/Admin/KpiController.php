<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;

class KpiController extends Controller
{
    public function __construct(private readonly KpiService $kpi) {}

    public function overview(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->kpi->overview()]);
    }

    public function education(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->kpi->education()]);
    }

    public function finance(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->kpi->finance()]);
    }

    public function departments(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->kpi->departments()]);
    }

    public function marketing(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->kpi->marketing()]);
    }

    public function partnerships(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->kpi->partnerships()]);
    }

    public function hr(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->kpi->hr()]);
    }
}
