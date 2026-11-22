<?php

namespace App\Notifications;

use App\Notifications\Concerns\TracksEmailLog;
use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends BaseResetPassword implements ShouldQueue
{
    use Queueable, TracksEmailLog;

    public const SUBJECT = 'إعادة تعيين كلمة المرور — منصة EMC';


    public function toMail($notifiable): MailMessage
    {
        // Build the frontend SPA reset URL directly — no named web route exists in this API project.
        $frontend = rtrim(config('app.frontend_url', config('app.url', 'http://localhost')), '/');
        $url = $frontend . '/reset-password?token=' . urlencode($this->token)
            . '&email=' . urlencode($notifiable->getEmailForPasswordReset());

        $fromAddress = config('mail.from.address', 'noreply@edumc.nl');
        $fromName    = config('mail.from.name', 'منصة EMC');
        $replyTo     = config('mail.support_email', 'support@edumc.nl');

        return (new MailMessage)
            ->from($fromAddress, $fromName)
            ->replyTo($replyTo)
            ->subject(self::SUBJECT)
            ->view('emails.password-reset', [
                'userName'    => $notifiable->name ?? $notifiable->email,
                'resetUrl'    => $url,
                'expiresMins' => config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60),
            ]);
    }
}
