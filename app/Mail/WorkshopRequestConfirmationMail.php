<?php

namespace App\Mail;

use App\Models\WorkshopRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WorkshopRequestConfirmationMail extends Mailable
{
    use SerializesModels;

    public WorkshopRequest $workshopRequest;

    public function __construct(WorkshopRequest $workshopRequest)
    {
        $this->workshopRequest = $workshopRequest;
    }

    public function build()
    {
        return $this->subject('تأكيد استلام طلب البرنامج التدريبي')
            ->markdown('emails.workshop-request-confirmation', [
                'workshopRequest' => $this->workshopRequest,
            ]);
    }
}
