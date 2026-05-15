<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\PartnerUser;
use Illuminate\Http\Request;

class PartnerReportController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $partner = PartnerUser::where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail()->partner;
        $reports = $partner->reports()->where('status', 'published')->latest()->get();

        return response()->json([
            'success' => true,
            'data'    => $reports->map(fn ($r) => [
                'id'      => $r->id,
                'title'   => $r->title,
                'summary' => $r->summary,
                'period'  => $r->period,
                'data'    => $r->data,
            ]),
        ]);
    }
}
