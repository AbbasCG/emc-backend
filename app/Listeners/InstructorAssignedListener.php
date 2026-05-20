<?php

namespace App\Listeners;

use App\Events\InstructorAssignedEvent;
use App\Services\NotificationService;

class InstructorAssignedListener
{
    public function handle(InstructorAssignedEvent $event): void
    {
        if (! $event->instructor->user_id) {
            return;
        }

        NotificationService::instructorAssigned(
            $event->instructor->user_id,
            $event->course->id,
            $event->course->title,
            $event->assignedByUserId,
        );
    }
}
