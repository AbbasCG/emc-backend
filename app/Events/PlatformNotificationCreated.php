<?php

namespace App\Events;

use App\Models\PlatformNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Ticket 8 completion pass — realtime notification delivery (Step 7).
 *
 * Broadcast on the private `notifications.{userId}` channel only — never a
 * public channel — so only the owning user's browser can ever receive the
 * payload (enforced server-side by routes/channels.php's ownership check,
 * not just by the frontend not subscribing to it).
 *
 * `ShouldBroadcastNow` (not `ShouldBroadcast`) because this is constructed
 * from inside `SendPlatformNotificationJob::handle()`, itself already
 * queued and already running strictly after the `DB::transaction()` commit
 * that created the row (`afterCommit()` on the job dispatch) — queuing the
 * broadcast again would just be a second, redundant hop through the same
 * queue connection with no commit-safety benefit.
 */
class PlatformNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly PlatformNotification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("notifications.{$this->notification->user_id}")];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->notification->id,
            'type'       => $this->notification->type,
            'title'      => $this->notification->title,
            'message'    => $this->notification->message,
            'action_url' => $this->notification->action_url,
            'meta_url'   => $this->notification->meta_url,
            'icon'       => $this->notification->icon,
            'color'      => $this->notification->color,
            'pinned'     => $this->notification->pinned,
            'created_at' => $this->notification->created_at?->toJSON(),
        ];
    }
}
