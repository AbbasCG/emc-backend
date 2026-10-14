<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TicketActionException;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketAccess;
use App\Services\TicketActions;
use App\Services\TicketOpsService;
use App\Services\TicketQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The ticket work queue's operational endpoints: statistics, board, analytics, insights,
 * assignment suggestions, duplicate hints, priority change and bulk actions.
 *
 * Every endpoint sits behind auth:sanctum and is scoped by TicketQuery::visible() / TicketAccess:
 * a user only ever gets figures and records for tickets they could already open one by one.
 * Reading never changes a ticket. Writing (priority, bulk) is checked per ticket by
 * TicketActions, so nothing depends on the buttons the frontend chose to show.
 */
class TicketOpsController extends Controller
{
    public function __construct(
        private readonly TicketQuery $query,
        private readonly TicketOpsService $ops,
        private readonly TicketAccess $access,
        private readonly TicketActions $actions,
    ) {}

    // GET /v1/tickets/stats
    public function stats(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->ops->stats($request->user(), $this->query->validate($request))]);
    }

    // GET /v1/tickets/board
    public function board(Request $request): JsonResponse
    {
        $filters = $this->query->validate($request);
        $perColumn = max(3, min(20, (int) $request->integer('per_column', 8)));

        return response()->json(['success' => true, 'data' => ['columns' => $this->ops->board($request->user(), $filters, $perColumn), 'per_column' => $perColumn]]);
    }

    // GET /v1/tickets/analytics?days=7|14|30|90
    public function analytics(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 30);
        if (! in_array($days, [7, 14, 30, 90], true)) {
            return response()->json(['success' => false, 'message' => 'النافذة الزمنية يجب أن تكون 7 أو 14 أو 30 أو 90 يومًا.', 'errors' => ['days' => ['قيمة غير صالحة.']]], 422);
        }

        return response()->json(['success' => true, 'data' => $this->ops->analytics($request->user(), $this->query->validate($request), $days)]);
    }

    // GET /v1/tickets/insights
    public function insights(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->ops->insights($request->user(), $this->query->validate($request))]);
    }

    // GET /v1/tickets/{id}/assignment-suggestions
    public function assignmentSuggestions(Request $request, int $id): JsonResponse
    {
        $ticket = $this->managedTicket($request, $id);
        if ($ticket instanceof JsonResponse) {
            return $ticket;
        }

        return response()->json(['success' => true, 'data' => $this->ops->assignmentSuggestions($ticket)]);
    }

    // GET /v1/tickets/{id}/possible-duplicates
    public function possibleDuplicates(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::find($id);
        // Reporter-only viewers get the public-safe projection; the hint is a staff tool.
        if (! $ticket || ! $this->access->canView($request->user(), $ticket) || $this->access->isReporterOnly($request->user(), $ticket)) {
            return $this->notAvailable();
        }

        return response()->json(['success' => true, 'data' => $this->ops->possibleDuplicates($request->user(), $ticket)]);
    }

    // PATCH /v1/tickets/{id}/priority
    public function priority(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'priority' => ['required', Rule::in(TicketActions::PRIORITIES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ], ['priority.required' => 'الأولوية مطلوبة.', 'priority.in' => 'الأولوية غير صالحة.']);

        try {
            $ticket = $this->actions->changePriority($id, $request->user(), $data['priority'], $data['reason'] ?? null);
        } catch (TicketActionException $e) {
            return $this->refused($e);
        }

        return response()->json(['success' => true, 'message' => 'تم تغيير الأولوية.', 'data' => ['id' => $ticket->id, 'priority' => $ticket->priority]]);
    }

    // POST /v1/tickets/bulk
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['priority', 'reassign'])],
            'ticket_ids' => ['required', 'array', 'min:1', 'max:'.TicketActions::MAX_BULK],
            'ticket_ids.*' => ['integer', 'min:1', 'distinct'],
            'priority' => ['required_if:action,priority', 'nullable', Rule::in(TicketActions::PRIORITIES)],
            'assigned_to_id' => ['required_if:action,reassign', 'nullable', 'integer', 'exists:users,id'],
            'unit_id' => ['nullable', 'integer', 'exists:departmental_units,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'ticket_ids.required' => 'اختر تذكرة واحدة على الأقل.',
            'ticket_ids.max' => 'الحد الأقصى '.TicketActions::MAX_BULK.' تذكرة في العملية الواحدة.',
            'priority.required_if' => 'اختر الأولوية الجديدة.',
            'assigned_to_id.required_if' => 'اختر المكلف الجديد.',
        ]);

        $result = $this->actions->bulk($request->user(), $data['action'], $data['ticket_ids'], $data);

        return response()->json([
            'success' => $result['succeeded'] > 0,
            'message' => $result['failed'] === 0 ? 'تم تنفيذ العملية على كل التذاكر.' : ($result['succeeded'] === 0 ? 'لم تُنفَّذ العملية على أي تذكرة.' : 'نُفِّذت العملية على بعض التذاكر فقط.'),
            'data' => $result,
        ]);
    }

    /** The ticket, when the viewer may manage its department; otherwise the response to return. */
    private function managedTicket(Request $request, int $id): Ticket|JsonResponse
    {
        $ticket = Ticket::find($id);
        if (! $ticket || ! $this->access->canView($request->user(), $ticket)) {
            return $this->notAvailable();
        }
        if (! $this->access->canManage($request->user(), (int) $ticket->department_id)) {
            return response()->json(['success' => false, 'message' => 'هذه الأداة متاحة فقط لقائد الإدارة المعنية.'], 403);
        }

        return $ticket;
    }

    private function notAvailable(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'التذكرة غير موجودة أو لا تملك صلاحية عليها.'], 404);
    }

    private function refused(TicketActionException $e): JsonResponse
    {
        return response()->json(array_filter(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors ?: null]), $e->status);
    }
}
