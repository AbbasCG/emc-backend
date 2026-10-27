<?php

namespace App\Services;

use App\Jobs\DispatchWebhookJob;
use App\Models\WebhookEndpoint;

/**
 * Fires all active webhook endpoints that listen to a given event.
 *
 * Usage:
 *   app(WebhookDispatchService::class)->fire('registration.created', ['id' => 1, ...]);
 */
class WebhookDispatchService
{
    /**
     * Supported events — define here for documentation and validation.
     */
    public const EVENTS = [
        'registration.created',
        'payment.confirmed',
        'payment.failed',
        'certificate.issued',
        'task.completed',
        'meeting.created',
        'partner.requested',
        'support.ticket.created',
        'form.submitted',
    ];

    /**
     * Queue delivery of a webhook event to all matching active endpoints.
     *
     * @param  string  $event    e.g. 'registration.created'
     * @param  array   $payload  Event data to send as JSON
     */
    public function fire(string $event, array $payload): void
    {
        WebhookEndpoint::query()
            ->where('status', 'active')
            ->get()
            ->filter(fn (WebhookEndpoint $ep) => $ep->listensTo($event))
            ->each(fn (WebhookEndpoint $ep) => DispatchWebhookJob::dispatch($ep->id, $event, $payload));
    }
}
