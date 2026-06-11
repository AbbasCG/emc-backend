<?php

namespace App\Services;

use App\Models\WorkshopRequest;

class WorkshopAnnouncementService
{
    public function generateAnnouncement(WorkshopRequest $workshopRequest): string
    {
        $locationTypes = implode(' / ', $workshopRequest->location_types ?? []);
        $categories = implode(' / ', $workshopRequest->categories ?? []);
        $proposedDate = $workshopRequest->proposed_date_1?->format('Y-m-d')
            ?? $workshopRequest->proposed_date?->format('Y-m-d')
            ?? $workshopRequest->getAttribute('proposed_date_1')
            ?? $workshopRequest->getAttribute('proposed_date');

        $priceText = $workshopRequest->price_type === 'paid'
            ? 'مدفوع - ' . $workshopRequest->price_amount . ' ريال'
            : 'مجاني';

        $announcement = <<<TEXT
يسر مركز EMC دعوتكم لحضور البرنامج التدريبي:

{$workshopRequest->program_name}

يقدمه:
{$workshopRequest->speaker_name}
{$workshopRequest->speaker_job_title}

محاور البرنامج:
{$workshopRequest->topics}

الفئة المستهدفة:
{$workshopRequest->target_audience}

التاريخ المقترح (الخيار الأول):
{$proposedDate}

التوقيت المقترح (الخيار الأول):
{$workshopRequest->proposed_time_1}

المكان / المنصة:
{$locationTypes}

رسوم الحضور:
{$priceText}

تصنيف البرنامج:
{$categories}
TEXT;

        return trim($announcement);
    }
}
