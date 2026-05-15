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
            'name'     => 'nullable|string|max:255',
            'email'    => 'nullable|email',
            'type'     => 'required|in:student,trainer,volunteer,technical,financial,partner',
            'priority' => 'in:low,medium,high,urgent',
            'subject'  => 'required|string|max:255',
            'message'  => 'required|string',
        ]);

        $data['user_id'] = $request->user()?->id;

        if (empty($data['name']) && $request->user()) {
            $data['name']  = $request->user()->name;
            $data['email'] = $request->user()->email;
        }

        SupportTicket::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Support ticket submitted. We will respond as soon as possible.',
        ], 201);
    }
}
