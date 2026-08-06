<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** GET /api/kpi?tab=overview|education|finance|departments|marketing|partnerships|hr */
    public function unified(Request $request): JsonResponse
    {
        $tab = $request->query('tab', 'overview');

        $data = match ($tab) {
            'education'     => $this->kpi->education(),
            'finance'       => $this->kpi->finance(),
            'departments'   => $this->kpi->departments(),
            'marketing'     => $this->kpi->marketing(),
            'partnerships'  => $this->kpi->partnerships(),
            'hr'            => $this->kpi->hr(),
            default         => $this->kpi->overview(),
        };

        return response()->json(['success' => true, 'data' => $data]);
    }
}
