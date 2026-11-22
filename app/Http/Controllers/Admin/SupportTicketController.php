<?php

namespace App\Http\Controllers\Admin;

use App\Services\NotificationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketReplyResource;
use App\Http\Resources\SupportTicketResource;
use App\Models\ActivityLog;
use App\Models\PlatformNotification;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $tickets = SupportTicket::with(['user', 'assignedTo'])
            ->when($request->search, function ($q) use ($request) {
                $s = '%' . $request->search . '%';
                $q->where(fn ($q2) => $q2
                    ->where('subject', 'like', $s)
                    ->orWhere('ticket_number', 'like', $s)
                    ->orWhere('full_name', 'like', $s)
                    ->orWhere('name', 'like', $s)
                    ->orWhere('email', 'like', $s)
                    ->orWhere('phone', 'like', $s)
                );
            })
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->request_type, fn ($q) => $q->where('request_type', $request->request_type))
            ->when($request->priority, fn ($q) => $q->where('priority', $request->priority))
            ->when($request->assigned_to, fn ($q) => $q->where('assigned_to', $request->assigned_to))
            ->when($request->unassigned, fn ($q) => $q->whereNull('assigned_to'))
            ->when($request->unresolved, fn ($q) => $q->whereNotIn('status', ['resolved', 'closed']))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->withCount('replies')
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => SupportTicketResource::collection($tickets)->response()->getData(true),
            'stats'   => [
                'total'       => SupportTicket::count(),
                'open'        => SupportTicket::whereNotIn('status', ['resolved', 'closed'])->count(),
                'unassigned'  => SupportTicket::whereNull('assigned_to')->whereNotIn('status', ['resolved', 'closed'])->count(),
                'resolved'    => SupportTicket::where('status', 'resolved')->count(),
                'high'        => SupportTicket::whereIn('priority', ['high', 'urgent'])->whereNotIn('status', ['resolved', 'closed'])->count(),
            ],
        ]);
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

        $isInternal = (bool) ($data['is_internal_note'] ?? false);

        $reply = $supportTicket->replies()->create([
            'user_id'          => $request->user()->id,
            'message'          => $data['message'],
            'is_internal_note' => $isInternal,
        ]);

        ActivityLogger::log('ticket.reply', $supportTicket, [
            'is_internal_note' => $isInternal,
        ], $request);

        $emailQueued  = false;
        $emailWarning = null;

        // Send email to requester on public replies only
        if (!$isInternal) {
            // Resolve recipient email with explicit fallback chain
            $recipientEmail = $supportTicket->email
                ?? $supportTicket->contact_email
                ?? $supportTicket->user?->email;

            $recipientName  = $supportTicket->full_name ?? $supportTicket->name ?? 'المستخدم';
            $ticketNumber   = $supportTicket->ticket_number ?? ('#' . $supportTicket->id);

            if ($recipientEmail && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                \App\Services\EmailService::dispatch(
                    'support_ticket_reply',
                    $recipientEmail,
                    $recipientName,
                    $supportTicket->user_id,
                    new \App\Mail\SupportTicketReplyMail(
                        $recipientName,
                        $ticketNumber,
                        $supportTicket->subject,
                        $data['message'],
                    ),
                    "رد على تذكرتك {$ticketNumber} — منصة EMC",
                    [],
                    [
                        'triggered_by_user_id' => $request->user()->id,
                        'related_ticket_id'    => $supportTicket->id,
                    ],
                );
                $emailQueued = true;
            } else {
                $emailWarning = 'لا يوجد بريد إلكتروني صالح لهذه التذكرة — لم يُرسل الرد بريدياً.';
            }
        }

        return response()->json([
            'success'       => true,
            'data'          => new SupportTicketReplyResource($reply->load('user')),
            'email_queued'  => $emailQueued,
            'email_warning' => $emailWarning,
        ], 201);
    }

    public function updateStatus(Request $request, SupportTicket $supportTicket): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'status'      => 'sometimes|in:new,in_progress,waiting_response,resolved,closed',
            'priority'    => 'sometimes|in:low,medium,high,urgent',
            'assigned_to' => 'sometimes|nullable|exists:users,id',
        ]);

        $oldValues = [
            'status'   => $supportTicket->status,
            'priority' => $supportTicket->priority,
        ];

        if (isset($data['status']) && $data['status'] === 'resolved' && !$supportTicket->resolved_at) {
            $data['resolved_at'] = now();
        }

        $previousAssignee = $supportTicket->assigned_to ? (int) $supportTicket->assigned_to : null;

        $supportTicket->update($data);

        // assigned_to is also accepted here; a real change of assignee is an assignment like assign().
        if (array_key_exists('assigned_to', $data)) {
            $this->notifyAssignment($supportTicket, $previousAssignee, $request->user());
        }

        ActivityLogger::log(
            isset($data['status']) ? 'ticket.status_changed' : 'ticket.priority_changed',
            $supportTicket,
            [],
            $request,
            $oldValues,
            $data
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث التذكرة.',
            'data'    => new SupportTicketResource($supportTicket->load(['user', 'assignedTo'])),
        ]);
    }

    public function assign(Request $request, SupportTicket $supportTicket): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'assigned_to' => 'required|exists:users,id',
        ]);

        $oldAssignee = $supportTicket->assigned_to;

        $supportTicket->update([
            'assigned_to' => $data['assigned_to'],
            'status'      => $supportTicket->status === 'new' ? 'in_progress' : $supportTicket->status,
        ]);

        $assignee  = User::find($data['assigned_to']);
        $assigner  = $request->user();

        ActivityLogger::log(
            'ticket.assigned',
            $supportTicket,
            [],
            $request,
            ['assigned_to' => $oldAssignee],
            ['assigned_to' => $data['assigned_to'], 'assigned_name' => $assignee?->name]
        );

        $this->notifyAssignment($supportTicket, $oldAssignee ? (int) $oldAssignee : null, $assigner);

        return response()->json([
            'success' => true,
            'message' => 'تم تعيين المسؤول.',
            'data'    => new SupportTicketResource($supportTicket->load(['user', 'assignedTo'])),
        ]);
    }

    public function resolve(Request $request, SupportTicket $supportTicket): \Illuminate\Http\JsonResponse
    {
        $supportTicket->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
        ]);

        ActivityLogger::log('ticket.resolved', $supportTicket, [], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم حل التذكرة.',
            'data'    => new SupportTicketResource($supportTicket->load(['user', 'assignedTo'])),
        ]);
    }

    /**
     * Tell the person a support ticket was just handed to (in-app + email, each channel decided by
     * the admin settings and their own preferences — see App\Support\TicketAssignmentNotifications).
     * Only a real change of assignee notifies. Assigning a ticket to yourself counts (channels still
     * follow the admin settings and your own preferences); assigning to someone inactive or
     * unassigning it sends nothing, and a failure to notify never fails the assignment.
     */
    private function notifyAssignment(SupportTicket $ticket, ?int $previousAssigneeId, ?User $assigner): void
    {
        $assigneeId = $ticket->assigned_to ? (int) $ticket->assigned_to : null;
        if ($assigneeId === null || $assigneeId === $previousAssigneeId) {
            return;
        }

        $assignee = User::find($assigneeId);
        if (! $assignee || ! $assignee->is_active) {
            return;
        }

        $at = now();
        try {
            NotificationService::supportTicketAssigned($assignee->id, $ticket, ($previousAssigneeId ?? 'none').'@'.$at->format('Uu'), $assigner?->id, $at);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function assignees(): \Illuminate\Http\JsonResponse
    {
        $allowedRoles = ['super_admin', 'admin', 'tech_admin', 'hr_manager', 'teacher', 'instructor', 'support_agent', 'volunteer'];

        // Active ticket counts per user
        $activeTicketCounts = SupportTicket::whereNotNull('assigned_to')
            ->whereNotIn('status', ['resolved', 'closed'])
            ->selectRaw('assigned_to, count(*) as count')
            ->groupBy('assigned_to')
            ->pluck('count', 'assigned_to');

        $users = User::whereIn('role', $allowedRoles)
            ->where('is_active', true)
            ->select(['id', 'name', 'email', 'role', 'department', 'avatar'])
            ->orderBy('department')
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => [
                'id'             => $u->id,
                'name'           => $u->name,
                'email'          => $u->email,
                'role'           => $u->role,
                'department'     => $u->department,
                'avatar'         => $u->avatar,
                'active_tickets' => $activeTicketCounts[$u->id] ?? 0,
            ]);

        return response()->json([
            'success' => true,
            'data'    => $users,
        ]);
    }

    public function activity(SupportTicket $supportTicket): \Illuminate\Http\JsonResponse
    {
        $logs = ActivityLog::where('entity_type', SupportTicket::class)
            ->where('entity_id', $supportTicket->id)
            ->with('user:id,name,avatar,role')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($log) => [
                'id'         => $log->id,
                'action'     => $log->action,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values ?? $log->changes,
                'user'       => $log->user ? [
                    'id'     => $log->user->id,
                    'name'   => $log->user->name,
                    'avatar' => $log->user->avatar,
                    'role'   => $log->user->role,
                ] : null,
                'created_at' => optional($log->created_at)->toISOString(),
            ]);

        return response()->json([
            'success' => true,
            'data'    => $logs,
        ]);
    }

    public function destroy(SupportTicket $supportTicket, Request $request): \Illuminate\Http\JsonResponse
    {
        ActivityLogger::log('ticket.deleted', $supportTicket, [], $request);
        $supportTicket->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف التذكرة.',
        ]);
    }
}
