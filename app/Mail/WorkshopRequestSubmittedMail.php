<?php

namespace App\Mail;

use App\Models\WorkshopRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class WorkshopRequestSubmittedMail extends Mailable
{
    use SerializesModels;

    public WorkshopRequest $workshopRequest;

    public function __construct(WorkshopRequest $workshopRequest)
    {
        $this->workshopRequest = $workshopRequest;
    }

    public function build()
    {
        $speakerPhotoUrl = $this->workshopRequest->speaker_photo
            ? Storage::disk('public')->url($this->workshopRequest->speaker_photo)
            : null;

        return $this->subject('طلب برنامج تدريبي جديد - ' . $this->workshopRequest->program_name)
            ->markdown('emails.workshop-request-submitted', [
                'workshopRequest' => $this->workshopRequest,
                'speakerPhotoUrl' => $speakerPhotoUrl,
            ]);
    }
}
