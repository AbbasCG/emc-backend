<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'full_name'    => 'required|string|max:255',
            'email'        => 'required|email|max:255',
            'phone'        => 'required|string|max:30',
            'request_type' => 'required|in:general,partnership,volunteer,technical',
            'subject'      => 'required|string|max:255',
            'message'      => 'required|string|max:5000',
            'priority'     => 'in:low,medium,high,urgent',
        ]);

        $typeMap = [
            'general'     => 'student',
            'partnership' => 'partner',
            'volunteer'   => 'volunteer',
            'technical'   => 'technical',
        ];

        SupportTicket::create([
            'user_id'      => $request->user()?->id,
            'full_name'    => $data['full_name'],
            'name'         => $data['full_name'],
            'email'        => $data['email'],
            'phone'        => $data['phone'],
            'request_type' => $data['request_type'],
            'type'         => $typeMap[$data['request_type']] ?? 'student',
            'subject'      => $data['subject'],
            'message'      => $data['message'],
            'priority'     => $data['priority'] ?? 'medium',
            'status'       => 'new',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم استلام طلبك. سيتواصل الفريق معك قريباً.',
        ], 201);
    }
}
