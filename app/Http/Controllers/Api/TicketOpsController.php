<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TicketActionException;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketAccess;
use App\Services\TicketActions;
use App\Services\TicketCreditService;
use App\Services\TicketDeploymentService;
use App\Services\TicketMyWork;
use App\Services\TicketOpsService;
use App\Services\TicketQuery;
use App\Services\TicketWorkEstimate;
use App\Support\TicketValidationMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
        private readonly TicketCreditService $credits,
        private readonly TicketDeploymentService $deployments,
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
        ], TicketValidationMessages::all());

        try {
            $ticket = $this->actions->changePriority($id, $request->user(), $data['priority'], $data['reason'] ?? null);
        } catch (TicketActionException $e) {
            return $this->refused($e);
        }

        return response()->json(['success' => true, 'message' => 'تم تغيير الأولوية.', 'data' => ['id' => $ticket->id, 'priority' => $ticket->priority]]);
    }

    // PATCH /v1/tickets/{id}/work-estimate
    public function workEstimate(Request $request, int $id): JsonResponse
    {
        $rules = TicketWorkEstimate::rules();
        $rules['task_scope'] = ['required', ...$rules['task_scope']];
        $rules['task_points'] = ['required', ...$rules['task_points']];
        $rules += TicketWorkEstimate::flagRules();
        $validator = Validator::make($request->all(), $rules, TicketValidationMessages::all() + TicketWorkEstimate::messages() + TicketWorkEstimate::flagMessages() + [
            'task_scope.required' => TicketWorkEstimate::MISSING_MESSAGE,
            'task_points.required' => TicketWorkEstimate::MISSING_MESSAGE,
        ]);

        try {
            // Same body as every other ticket-action refusal: {success:false, message, errors}.
            if ($validator->fails()) {
                $first = $validator->errors()->first();
                throw new TicketActionException($first, 422, $validator->errors()->toArray());
            }
            $data = $validator->validated();
            $ticket = $this->actions->updateEstimate($id, $request->user(), $data);
        } catch (TicketActionException $e) {
            return $this->refused($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث تقدير العمل.',
            'data' => $this->estimatePayload($ticket->fresh(), $request->user()),
        ]);
    }

    // POST /v1/tickets/{id}/confirm-review  (the one-click «تأكيد المراجعة»)
    public function confirmReview(Request $request, int $id): JsonResponse
    {
        // NO validation here: the request concerns the stage transition only. The optional `note` and the refusal of any value
        // field (REVIEW_VALUES_LOCKED) are judged by the service AFTER the authorization and stage checks, so a reporter or a
        // plain member cannot probe the request shape (422) instead of getting the same 403/404 as with a valid body.
        try {
            $ticket = $this->deployments->confirmReview($id, $request->user(), $request->input('note'), $this->sentValues($request), $request->input('review_round'));
        } catch (TicketActionException $e) {
            return $this->refused($e);
        }
        $deployment = $this->access->isDeploymentTicket($ticket);

        return $this->deploymentResponse($ticket, $request, $deployment
            ? 'تم تأكيد المراجعة واعتماد العمل للنشر على الموقع الحي. لم تُضَف أي نقاط أو ساعات بعد؛ تُضاف بعد تحقق المكلّف على الموقع.'
            : 'تم تأكيد المراجعة وإغلاق التذكرة وإضافة النقاط والساعات إلى رصيد المكلف.');
    }

    // POST /v1/tickets/{id}/approve-completion  (kept for compatibility: the same confirmation, classic tickets only)
    public function approveCompletion(Request $request, int $id): JsonResponse
    {
        try {
            $ticket = $this->credits->approveCompletion($id, $request->user(), $request->input('note'), $this->sentValues($request), $request->input('review_round'));
        } catch (TicketActionException $e) {
            return $this->refused($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم اعتماد إنجاز التذكرة وإضافة النقاط والساعات إلى رصيد المكلف.',
            'data' => $this->ticketPayload($ticket->fresh(['department', 'unit', 'assignee', 'creditor:id,name', 'activityLogs']), $request->user()),
        ]);
    }

    // POST /v1/tickets/{id}/approve-for-deploy  (kept for compatibility: the same confirmation, deployment tickets only)
    public function approveForDeploy(Request $request, int $id): JsonResponse
    {
        try {
            $ticket = $this->deployments->approveForDeploy($id, $request->user(), $request->input('note'), $this->sentValues($request), $request->input('review_round'));
        } catch (TicketActionException $e) {
            return $this->refused($e);
        }

        return $this->deploymentResponse($ticket, $request, 'تم اعتماد العمل للنشر على الموقع الحي. لم تُضَف أي نقاط أو ساعات بعد؛ تُضاف بعد تحقق المكلّف على الموقع.');
    }

    /** The read-only review values (size, points, approximate and actual duration) a request body tries to send. @return array<string, mixed> */
    private function sentValues(Request $request): array
    {
        $all = $request->all();

        return array_intersect_key($all, array_flip(TicketWorkEstimate::lockedKeysIn($all)));
    }

    // POST /v1/tickets/{id}/mark-deployed
    public function markDeployed(Request $request, int $id): JsonResponse
    {
        try {
            $ticket = $this->deployments->markDeployed($id, $request->user(), $request->input('live_url'), $request->input('note'));
        } catch (TicketActionException $e) {
            return $this->refused($e);
        }

        return $this->deploymentResponse($ticket, $request, 'تم تسجيل النشر على الموقع الحي، وأُبلغ المكلّف ليتحقق منه.');
    }

    // POST /v1/tickets/{id}/verify-resolved
    public function verifyResolved(Request $request, int $id): JsonResponse
    {
        try {
            $ticket = $this->deployments->verifyResolved($id, $request->user(), $request->input('note'));
        } catch (TicketActionException $e) {
            return $this->refused($e);
        }

        return $this->deploymentResponse($ticket, $request, 'تم التحقق من الحل على الموقع الحي وإغلاق التذكرة وإضافة النقاط والساعات إلى رصيدك.');
    }

    // POST /v1/tickets/{id}/verify-failed
    public function verifyFailed(Request $request, int $id): JsonResponse
    {
        try {
            $ticket = $this->deployments->verifyFailed($id, $request->user(), $request->input('description'));
        } catch (TicketActionException $e) {
            return $this->refused($e);
        }

        return $this->deploymentResponse($ticket, $request, 'سُجّلت المشكلة وأُعيدت التذكرة إلى قيد التنفيذ؛ يلزم مراجعة واعتماد ونشر جديد.');
    }

    private function deploymentResponse(Ticket $ticket, Request $request, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->ticketPayload($ticket->fresh(['department', 'unit', 'assignee', 'creditor:id,name', 'reviewApprover:id,name', 'deployer:id,name', 'activityLogs']), $request->user()),
        ]);
    }
    // POST /v1/tickets/{id}/return-for-rework
    public function returnForRework(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], TicketValidationMessages::all() + [
            'reason.required' => 'اكتب سبب إعادة التذكرة للتنفيذ.',
            'reason.string' => 'السبب غير صالح.',
            'reason.min' => 'سبب الإعادة قصير؛ اكتب 5 أحرف على الأقل.',
            'reason.max' => 'سبب الإعادة طويل جدًا؛ الحد الأقصى 1000 حرف.',
        ]);

        try {
            if ($validator->fails()) {
                throw new TicketActionException((string) $validator->errors()->first(), 422, $validator->errors()->toArray());
            }
            $ticket = $this->actions->returnForRework($id, $request->user(), $validator->validated()['reason']);
        } catch (TicketActionException $e) {
            return $this->refused($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'تمت إعادة التذكرة للمكلف لاستكمال العمل.',
            'data' => $this->ticketPayload($ticket->fresh(['department', 'unit', 'assignee', 'activityLogs']), $request->user()),
        ]);
    }
    // GET /v1/tickets/my-work
    public function myWork(Request $request, TicketMyWork $myWork): JsonResponse
    {
        // No query parameter can widen the set (only `status` narrows it): the caller's own assignments, always.
        $filter = $request->validate([
            'status' => ['sometimes', 'nullable', 'string'],
        ], ['status.string' => 'حالة غير صالحة.'])['status'] ?? null;
        $statuses = $filter === null || $filter === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $filter))));
        if (array_diff($statuses, TicketMyWork::STATUSES) !== []) {
            return response()->json(['success' => false, 'message' => 'حالة غير صالحة.', 'errors' => ['status' => ['حالة غير صالحة.']]], 422);
        }

        $result = $myWork->forUser($request->user(), $statuses);

        return response()->json(['success' => true, 'data' => $result['data'], 'meta' => $result['meta']]);
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
            ...TicketWorkEstimate::rules(),
            ...TicketWorkEstimate::flagRules(),
        ], TicketWorkEstimate::messages() + TicketWorkEstimate::flagMessages() + TicketValidationMessages::all() + [
            'ticket_ids.required' => 'اختر تذكرة واحدة على الأقل.',
            'ticket_ids.max' => 'الحد الأقصى '.TicketActions::MAX_BULK.' تذكرة في العملية الواحدة.',
            'priority.required_if' => 'اختر الأولوية الجديدة.',
            'assigned_to_id.required_if' => 'اختر المكلف الجديد.',
        ]);

        // Both given and inconsistent: the same answer for every ticket, so refuse the request up front
        // (a partial estimate is combined with each ticket's own stored value and checked per ticket).
        if ($data['action'] === 'reassign' && ! empty($data['task_scope']) && isset($data['task_points'])) {
            $check = \App\Services\TaskWorkflowService::validatePointsForScope($data['task_scope'], (int) $data['task_points']);
            if (! ($check['valid'] ?? false)) {
                return response()->json(['success' => false, 'message' => $check['message'], 'errors' => ['task_points' => [$check['message']]]], 422);
            }
        }

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

    /** @return array<string, mixed> */
    private function estimatePayload(Ticket $ticket, $user): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'task_scope' => $ticket->task_scope,
            'task_points' => $ticket->task_points,
            'estimated_hours' => $ticket->estimated_hours,
            'requires_deployment' => (bool) $ticket->requires_deployment,
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'capabilities' => $this->access->capabilities($user, $ticket),
        ];
    }

    /**
     * The ticket as the caller may read it: the serialized row, capabilities and (for managers, global roles and the
     * assignee) the `credit` block; everyone else gets the redacted form.
     *
     * @return array<string, mixed>
     */
    private function ticketPayload(Ticket $ticket, $user): array
    {
        $data = $ticket->toArray();
        $data['capabilities'] = $this->access->capabilities($user, $ticket);

        return $this->access->redactInternal($user, $ticket, $data);
    }
    private function notAvailable(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'التذكرة غير موجودة أو لا تملك صلاحية عليها.'], 404);
    }

    private function refused(TicketActionException $e): JsonResponse
    {
        return response()->json($e->body(), $e->status);
    }
}
