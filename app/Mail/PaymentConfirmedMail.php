<?php

namespace App\Mail;

use App\Models\Registration;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmedMail extends Mailable
{
    use SerializesModels;


    public function __construct(
        public readonly string  $studentName,
        public readonly string  $courseTitle,
        public readonly string  $registrationId,
        public readonly ?string $amount,
        public readonly string  $currency,
        public readonly ?string $paymentMethod,
        public readonly string  $paidAt,
        public readonly ?string $dashboardUrl = null,
    ) {}

    public static function fromRegistration(Registration $registration): self
    {
        $payment = $registration->payments()->latest()->first();
        return new self(
            studentName:    $registration->full_name ?? $registration->user?->name ?? '',
            courseTitle:    $registration->course?->title ?? '',
            registrationId: (string) $registration->id,
            amount:         $payment ? number_format($payment->amount, 2) : null,
            currency:       $payment?->currency ?? 'USD',
            paymentMethod:  $payment?->provider ?? null,
            paidAt:         now()->format('Y-m-d H:i'),
            dashboardUrl:   rtrim(config('app.frontend_url', config('app.url')), '/') . '/dashboard/my-courses',
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تأكيد الدفع وإتمام التسجيل — ' . $this->courseTitle);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-confirmed',
            with: [
                'studentName'    => $this->studentName,
                'courseTitle'    => $this->courseTitle,
                'registrationId' => $this->registrationId,
                'amount'         => $this->amount,
                'currency'       => $this->currency,
                'paymentMethod'  => $this->paymentMethod,
                'paidAt'         => $this->paidAt,
                'dashboardUrl'   => $this->dashboardUrl ?? rtrim(config('app.frontend_url', config('app.url')), '/') . '/dashboard/my-courses',
            ],
        );
    }
}
