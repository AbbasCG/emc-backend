<?php

namespace App\Listeners;

use App\Events\WorkshopRequestSubmittedEvent;
use App\Services\NotificationService;

class WorkshopRequestListener
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
