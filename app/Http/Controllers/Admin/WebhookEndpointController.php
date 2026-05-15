<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookEndpointController extends Controller
{
    public function index(): JsonResponse
    {
        $endpoints = WebhookEndpoint::query()
            ->withCount('deliveries')
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $endpoints->items(),
            'meta'    => [
                'current_page' => $endpoints->currentPage(),
                'last_page'    => $endpoints->lastPage(),
                'total'        => $endpoints->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'   => 'required|string|max:255',
            'url'    => 'required|url',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:' . implode(',', WebhookDispatchService::EVENTS) . ',*',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $data['secret'] = Str::random(40);

        $endpoint = WebhookEndpoint::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Webhook endpoint created.',
            'data'    => $endpoint,
            'secret'  => $data['secret'], // shown only once
        ], 201);
    }

    public function show(WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $webhookEndpoint->loadCount('deliveries'),
        ]);
    }

    public function update(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'url'      => 'sometimes|url',
            'events'   => 'sometimes|array|min:1',
            'events.*' => 'string',
            'status'   => 'sometimes|in:active,inactive',
        ]);

        $webhookEndpoint->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Webhook endpoint updated.',
            'data'    => $webhookEndpoint->fresh(),
        ]);
    }

    public function destroy(WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $webhookEndpoint->delete();

        return response()->json(['success' => true, 'message' => 'Webhook endpoint deleted.']);
    }

    /** POST /admin/webhook-endpoints/{id}/test — send a test ping. */
    public function test(WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        DispatchWebhookJob::dispatch($webhookEndpoint->id, 'test.ping', [
            'event'     => 'test.ping',
            'message'   => 'EMC webhook test delivery',
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Test delivery queued.',
        ]);
    }

    /** GET /admin/webhook-deliveries */
    public function deliveries(Request $request): JsonResponse
    {
        $deliveries = WebhookDelivery::query()
            ->with('endpoint:id,name,url')
            ->when($request->endpoint_id, fn ($q) => $q->where('endpoint_id', $request->endpoint_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->event, fn ($q) => $q->where('event', $request->event))
            ->latest()
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data'    => $deliveries->items(),
            'meta'    => [
                'current_page' => $deliveries->currentPage(),
                'last_page'    => $deliveries->lastPage(),
                'total'        => $deliveries->total(),
            ],
        ]);
    }
}
