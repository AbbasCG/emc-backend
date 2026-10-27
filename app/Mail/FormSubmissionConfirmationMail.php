<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Smart Forms Phase 8: the receipt a respondent gets when the form's confirmation is switched on. It says which form, the
 * response number and when it was received — never the answers (the address may have been typed by someone else).
 */
class FormSubmissionConfirmationMail extends Mailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $formTitle,
        public readonly int $submissionId,
        public readonly string $submittedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تم استلام ردك: '.mb_strimwidth($this->formTitle, 0, 90, '…'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.form-submission-confirmation',
            with: [
                'recipientName' => $this->recipientName,
                'formTitle'     => $this->formTitle,
                'submissionId'  => $this->submissionId,
                'submittedAt'   => $this->submittedAt,
            ],
        );
    }
}
