<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        // Use frontend URL if configured so the link lands on the SPA
        $frontend = rtrim(config('app.frontend_url', ''), '/');
        if ($frontend) {
            $url = $frontend . '/reset-password?token=' . urlencode($this->token)
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        }

        return (new MailMessage)
            ->subject('إعادة تعيين كلمة المرور — منصة EMC')
            ->view('emails.password-reset', [
                'userName'   => $notifiable->name ?? $notifiable->email,
                'resetUrl'   => $url,
                'expiresMins' => config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60),
            ]);
    }
}
