<?php

namespace App\Listeners;

use App\Events\StudentRegisteredEvent;
use App\Services\NotificationService;

class StudentRegisteredListener
{
    public function handle(StudentRegisteredEvent $event): void
    {
        $reg = $event->registration;

        if (! $reg->user_id) {
            return;
        }

        $courseTitle = $reg->course?->title ?? 'الدورة';

        NotificationService::studentRegistered(
            $reg->user_id,
            $reg->course_id,
            $courseTitle,
            $reg->id,
        );
    }
}
