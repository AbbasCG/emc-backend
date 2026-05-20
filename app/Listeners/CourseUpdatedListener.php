<?php

namespace App\Listeners;

use App\Events\CourseUpdatedEvent;
use App\Services\NotificationService;

class CourseUpdatedListener
{
    public function handle(CourseUpdatedEvent $event): void
    {
        $course  = $event->course;
        $changes = $event->changes;

        if (isset($changes['status'])) {
            if ($changes['status'] === 'cancelled') {
                NotificationService::courseCancelled($course->id, $course->title);
                return;
            }

            if ($changes['status'] === 'completed') {
                NotificationService::courseCompleted($course->id, $course->title);
                return;
            }

            if (isset($changes['registration_open']) && $changes['registration_open'] === true) {
                NotificationService::registrationOpened($course->id, $course->title, $course->department_id);
            }
        }

        if (isset($changes['start_date']) && $course->instructor?->user_id) {
            NotificationService::courseDateUpdated(
                $course->id,
                $course->title,
                $changes['start_date'],
                $course->instructor->user_id,
                $course->department_id,
            );
        }
    }
}
