<?php

namespace App\Listeners;

use App\Events\InstructorAssignedEvent;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class InstructorAssignedListener implements ShouldQueue
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
