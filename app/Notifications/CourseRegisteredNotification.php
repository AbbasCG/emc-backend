<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CourseRegisteredNotification extends Notification
{
    public function __construct(
        private int $courseId,
        private string $courseTitle,
        private int $registrationId,
        private string $registrationStatus,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $isPending = str_contains(strtolower($this->registrationStatus), 'pending');

        return [
            'title'           => $isPending ? 'تم استلام طلب تسجيلك' : 'تم تسجيلك في الدورة',
            'body'            => $isPending
                ? 'تم استلام طلب تسجيلك في الدورة: ' . $this->courseTitle . '. يرجى إكمال الدفع لتأكيد مكانك.'
                : 'تم تسجيلك بنجاح في الدورة: ' . $this->courseTitle . '. يمكنك متابعة المحتوى من لوحتك.',
            'type'            => 'course_registered',
            'course_id'       => $this->courseId,
            'course_title'    => $this->courseTitle,
            'registration_id' => $this->registrationId,
            'status'          => $this->registrationStatus,
        ];
    }
}
