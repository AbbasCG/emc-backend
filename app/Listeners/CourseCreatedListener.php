<?php

namespace App\Listeners;

use App\Events\CourseCreatedEvent;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class CourseCreatedListener implements ShouldQueue
{
    public function handle(CourseCreatedEvent $event): void
    {
        NotificationService::courseCreated(
            $event->course->id,
            $event->course->title,
            $event->course->department_id,
            $event->createdByUserId,
        );
    }
}
