<?php

namespace App\Mail;

use App\Models\VolunteerRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VolunteerApplicationAdminMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $adminName,
        public readonly string  $applicantName,
        public readonly string  $applicantEmail,
        public readonly string  $applicantPhone,
        public readonly string  $department,
        public readonly string  $experienceLevel,
        public readonly ?string $skills,
        public readonly string  $submittedAt,
        public readonly string  $reviewUrl,
    ) {}

    public static function fromRequest(VolunteerRequest $req, string $adminName, int $requestId): self
    {
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');
        return new self(
            adminName:       $adminName,
            applicantName:   $req->full_name,
            applicantEmail:  $req->email,
            applicantPhone:  $req->phone,
            department:      $req->desired_department,
            experienceLevel: $req->experience_level,
            skills:          $req->skills,
            submittedAt:     now()->format('Y-m-d H:i'),
            reviewUrl:       $frontend . '/dashboard/super-admin/volunteer-requests/' . $requestId,
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'طلب تطوع جديد — ' . $this->applicantName);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.volunteer-admin-notification',
            with: [
                'adminName'       => $this->adminName,
                'applicantName'   => $this->applicantName,
                'applicantEmail'  => $this->applicantEmail,
                'applicantPhone'  => $this->applicantPhone,
                'department'      => $this->department,
                'experienceLevel' => $this->experienceLevel,
                'skills'          => $this->skills,
                'submittedAt'     => $this->submittedAt,
                'reviewUrl'       => $this->reviewUrl,
            ],
        );
    }
}
