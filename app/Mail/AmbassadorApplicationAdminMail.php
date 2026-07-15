<?php

namespace App\Mail;

use App\Models\AmbassadorApplication;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AmbassadorApplicationAdminMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $adminName,
        public readonly string $applicantName,
        public readonly string $applicantEmail,
        public readonly string $university,
        public readonly string $major,
        public readonly string $country,
        public readonly string $submittedAt,
        public readonly string $reviewUrl,
    ) {}

    public static function fromApplication(AmbassadorApplication $app, string $adminName): self
    {
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');

        return new self(
            adminName:    $adminName,
            applicantName: $app->full_name,
            applicantEmail: $app->email,
            university:   $app->university_name,
            major:        $app->major ?? '—',
            country:      $app->country,
            submittedAt:  now()->format('Y-m-d H:i'),
            reviewUrl:    $frontend . '/dashboard/super-admin/ambassador-applications/' . $app->id,
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'طلب سفير تحول رقمي جديد — ' . $this->applicantName);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ambassador-admin-notification',
            with: [
                'adminName'      => $this->adminName,
                'applicantName'  => $this->applicantName,
                'applicantEmail' => $this->applicantEmail,
                'university'     => $this->university,
                'major'          => $this->major,
                'country'        => $this->country,
                'submittedAt'    => $this->submittedAt,
                'reviewUrl'      => $this->reviewUrl,
            ],
        );
    }
}
