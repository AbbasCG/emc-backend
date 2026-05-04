<?php

namespace App\Mail;

use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CourseRegistrationConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public Registration $registration;

    public function __construct(Registration $registration)
    {
        $this->registration = $registration;
    }

    public function build()
    {
        return $this->subject('تأكيد تسجيلك في الدورة')
            ->markdown('emails.course-registration-confirmation', [
                'registration' => $this->registration,
            ]);
    }
}
