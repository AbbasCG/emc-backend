<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class InstructorAssignedNotification extends Notification
{
    public function __construct(
        private string $entityType,
        private int $entityId,
        private string $entityTitle,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $isWorkshop = $this->entityType === 'workshop';

        return [
            'title'        => 'تم إسناد ' . ($isWorkshop ? 'ورشة' : 'دورة') . ' جديدة إليك',
            'body'         => 'تم ربطك بـ' . ($isWorkshop ? 'الورشة' : 'الدورة') . ': ' . $this->entityTitle . '. يرجى مراجعة التفاصيل من لوحة المدرب.',
            'type'         => 'instructor_assigned',
            'entity_type'  => $this->entityType,
            'entity_id'    => $this->entityId,
            'entity_title' => $this->entityTitle,
        ];
    }
}
