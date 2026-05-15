<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\PartnerUser;
use Illuminate\Http\Request;

class PartnerDashboardController extends Controller
{
    public function __invoke(Request $request): \Illuminate\Http\JsonResponse
    {
        $user        = $request->user();
        $partnerUser = PartnerUser::with('partner')->where('user_id', $user->id)->where('status', 'active')->first();

        if (! $partnerUser) {
            return response()->json(['success' => false, 'message' => 'No active partner profile found.'], 404);
        }

        $partner = $partnerUser->partner->load(['programs', 'reports', 'documents']);

        return response()->json([
            'success' => true,
            'data'    => [
                'partner'          => [
                    'id'             => $partner->id,
                    'name'           => $partner->name,
                    'type'           => $partner->type,
                    'status'         => $partner->status,
                    'contact_person' => $partner->contact_person,
                    'email'          => $partner->email,
                ],
                'programs_count'   => $partner->programs->count(),
                'active_programs'  => $partner->programs->where('status', 'active')->count(),
                'reports_count'    => $partner->reports->count(),
                'documents_count'  => $partner->documents->count(),
                'your_role'        => $partnerUser->role_title,
            ],
        ]);
    }
}
