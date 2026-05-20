<?php

namespace App\Listeners;

use App\Events\CourseCreatedEvent;
use App\Services\NotificationService;

class CourseCreatedListener
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
