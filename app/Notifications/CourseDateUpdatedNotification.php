<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use Illuminate\Notifications\Notification;

class CourseDateUpdatedNotification extends Notification
{
    public function __construct(
        private int $courseId,
        private string $courseTitle,
        private ?string $newStartDate,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = [];
        if (NotificationPreference::isEnabled($notifiable->id, 'in_app', 'session_date_updated')) {
            $channels[] = 'database';
        }
        return $channels ?: ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $dateText = $this->newStartDate
            ? 'تاريخ البدء الجديد: ' . $this->newStartDate
            : 'لم يتم تحديد تاريخ بداية بعد.';

        return [
            'title'          => 'تم تحديث موعد الدورة',
            'body'           => 'تم تحديث موعد الدورة: ' . $this->courseTitle . '. ' . $dateText,
            'type'           => 'course_date_updated',
            'course_id'      => $this->courseId,
            'course_title'   => $this->courseTitle,
            'new_start_date' => $this->newStartDate,
        ];
    }
}
