<?php

namespace App\Listeners\Certificate;

use App\Events\Course\CourseCompleted;
use App\Services\Certificate\CertificateAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class IssueCertificateAfterCourseCompletion implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue   = 'certificates';
    public int    $tries   = 3;
    public int    $timeout = 60;

    public function handle(CourseCompleted $event): void
    {
        // Skip if the course is not set up for auto-certificate
        if (isset($event->course->certificate_available) && ! $event->course->certificate_available) {
            return;
        }

        try {
            app(CertificateAutomationService::class)->issueForCourseCompletion($event->user, $event->course);
        } catch (\Throwable $e) {
            Log::error('[CertificateListener] CourseCompleted → automation failed', [
                'user_id'   => $event->user->id,
                'course_id' => $event->course->id,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
