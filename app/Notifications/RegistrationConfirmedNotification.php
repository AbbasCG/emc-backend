<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class RegistrationConfirmedNotification extends Notification
{
    public function __construct(
        private int $courseId,
        private string $courseTitle,
        private int $registrationId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title'           => 'تم تأكيد تسجيلك',
            'body'            => 'تم تأكيد تسجيلك في الدورة: ' . $this->courseTitle . '. يمكنك الآن متابعة المحتوى من لوحتك.',
            'type'            => 'registration_confirmed',
            'course_id'       => $this->courseId,
            'course_title'    => $this->courseTitle,
            'registration_id' => $this->registrationId,
        ];
    }
}
