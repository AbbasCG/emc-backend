<?php

namespace App\Mail\Concerns;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Log;

/**
 * Shared sender-resolution logic for Mailables that must originate from
 * support@edumc.nl via the dedicated 'support' SMTP mailer.
 *
 * Enforces the invariant: From address must always match the SMTP login it is
 * sent through. If the support mailer's credentials aren't configured, this
 * does NOT silently claim From: support@edumc.nl while sending through the
 * default (noreply) SMTP login — that produces "553 Sender address rejected:
 * not owned by user" on strict providers (e.g. Hostinger). Instead it logs a
 * clear warning and falls back to the default mailer with the default From
 * address, leaving support@edumc.nl reachable only via Reply-To.
 */
trait UsesSupportSender
{
    /**
     * Resolves the From address for this Mailable and, if the support mailer
     * is properly configured, switches this Mailable onto it via ->mailer().
     * Call this from envelope() before building the Envelope.
     */
    protected function resolveSupportSender(): Address
    {
        if ($this->supportMailerIsConfigured()) {
            $this->mailer('support');

            return new Address(
                (string) config('mail.support_from_address', 'support@edumc.nl'),
                (string) config('mail.support_from_name', 'EMC Support'),
            );
        }

        Log::warning('UsesSupportSender: support mailer is not configured (missing SUPPORT_MAIL_USERNAME/PASSWORD) — falling back to the default mailer to avoid a rejected-sender SMTP error.', [
            'mailable' => static::class,
        ]);

        // Stay on the default mailer — From must match its SMTP login.
        return new Address(
            (string) config('mail.from.address'),
            (string) config('mail.from.name'),
        );
    }

    protected function supportMailerIsConfigured(): bool
    {
        return filled(config('mail.mailers.support.username'))
            && filled(config('mail.mailers.support.password'));
    }
}
