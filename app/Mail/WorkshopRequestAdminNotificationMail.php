<?php

namespace App\Mail;

use App\Models\WorkshopRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkshopRequestAdminNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly WorkshopRequest $workshopRequest,
        public readonly string          $adminName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'طلب برنامج تدريبي جديد — ' . ($this->workshopRequest->title ?? 'طلب جديد'));
    }

    public function content(): Content
    {
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');
        return new Content(
            view: 'emails.workshop-request-admin-notification',
            with: [
                'adminName'       => $this->adminName,
                'workshopRequest' => $this->workshopRequest,
                'reviewUrl'       => $frontend . '/dashboard/admin/workshop-requests/' . $this->workshopRequest->id,
            ],
        );
    }
}
