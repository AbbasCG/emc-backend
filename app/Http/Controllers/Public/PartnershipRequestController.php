<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\PartnershipRequest;
use Illuminate\Http\Request;

class PartnershipRequestController extends Controller
{
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'partner_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'email'        => 'required|email',
            'phone'        => 'nullable|string|max:50',
            'type'         => 'required|in:university,school,company,institution,donor,community',
            'message'      => 'required|string',
        ]);

        PartnershipRequest::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Partnership request submitted. We will be in touch soon.',
        ], 201);
    }
}
