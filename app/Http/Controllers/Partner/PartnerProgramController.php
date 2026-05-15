<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\PartnerUser;
use Illuminate\Http\Request;

class PartnerProgramController extends Controller
{
    private function getPartner(Request $request)
    {
        return PartnerUser::where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->firstOrFail()
            ->partner;
    }

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $programs = $this->getPartner($request)->programs()->with(['course', 'track'])->get();

        return response()->json([
            'success' => true,
            'data'    => $programs->map(fn ($p) => [
                'id'                => $p->id,
                'title'             => $p->title,
                'description'       => $p->description,
                'status'            => $p->status,
                'start_date'        => $p->start_date,
                'end_date'          => $p->end_date,
                'participant_count' => $p->participant_count,
                'course'            => $p->course?->title,
                'track'             => $p->track?->name,
            ]),
        ]);
    }
}
