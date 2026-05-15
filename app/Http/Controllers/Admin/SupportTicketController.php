<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketReplyResource;
use App\Http\Resources\SupportTicketResource;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $tickets = SupportTicket::with(['user', 'assignedTo'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->priority, fn ($q) => $q->where('priority', $request->priority))
            ->when($request->assigned_to, fn ($q) => $q->where('assigned_to', $request->assigned_to))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => SupportTicketResource::collection($tickets)->response()->getData(true)]);
    }

    public function show(SupportTicket $supportTicket): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new SupportTicketResource(
                $supportTicket->load(['user', 'assignedTo', 'replies.user'])
            ),
        ]);
    }

    public function reply(Request $request, SupportTicket $supportTicket): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'message'          => 'required|string',
            'is_internal_note' => 'boolean',
        ]);

        $reply = $supportTicket->replies()->create([
            'user_id'          => $request->user()->id,
            'message'          => $data['message'],
            'is_internal_note' => $data['is_internal_note'] ?? false,
        ]);

        ActivityLogger::log('ticket.reply', $supportTicket, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new SupportTicketReplyResource($reply->load('user')),
        ], 201);
    }

    public function updateStatus(Request $request, SupportTicket $supportTicket): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'status'      => 'required|in:new,in_progress,waiting_response,resolved,closed',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $supportTicket->update($data);

        ActivityLogger::log('ticket.status_changed', $supportTicket, $data, $request);

        return response()->json([
            'success' => true,
            'message' => 'Ticket updated.',
            'data'    => new SupportTicketResource($supportTicket->load(['user', 'assignedTo'])),
        ]);
    }
}
