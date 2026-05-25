<?php

namespace App\Listeners;

use App\Events\WorkshopRequestSubmittedEvent;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class WorkshopRequestListener implements ShouldQueue
{
    public function handle(WorkshopRequestSubmittedEvent $event): void
    {
        $req = $event->workshopRequest;

        NotificationService::workshopRequestSubmitted(
            $req->id,
            $req->program_name,
            $req->requester_name,
        );
    }
}
