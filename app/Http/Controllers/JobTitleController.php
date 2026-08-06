<?php

namespace App\Http\Controllers;

use App\Models\JobTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobTitleController extends Controller
{
    // GET /api/job-titles?department_id=
    public function index(Request $request): JsonResponse
    {
        $query = JobTitle::query()->where('is_active', true)->orderBy('sort_order');

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(['id', 'department_id', 'name_ar'])->map(fn ($jt) => [
                'id' => $jt->id, 'department_id' => $jt->department_id, 'name' => $jt->name_ar,
            ]),
        ]);
    }
}
