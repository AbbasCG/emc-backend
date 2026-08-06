<?php

namespace App\Services\WhatsApp;

use App\Models\NotificationLog;
use App\Models\NotificationTemplate;

/**
 * High-level WhatsApp notification facade.
 *
 * Resolves the correct provider from config, applies templates,
 * and writes to notification_logs.
 *
 * Usage:
 *   app(WhatsAppNotificationService::class)->send($user->phone, 'certificate_issued', [
 *       'name'   => $user->name,
 *       'course' => $course->title,
 *   ]);
 */
class WhatsAppNotificationService
{
    private WhatsAppProvider $provider;

    public function __construct()
    {
        $this->provider = $this->resolveProvider();
    }

    /**
     * Send a notification using a template key.
     *
     * @param  string  $to        E.164 phone number, e.g. +966512345678
     * @param  string  $type      Notification type key, e.g. certificate_issued
     * @param  array   $variables Template variables
     * @param  int|null $userId   For logging
     */
    public function send(string $to, string $type, array $variables = [], ?int $userId = null): bool
    {
        $rendered = NotificationTemplate::render($type, 'whatsapp', $variables);

        // Fallback: send a generic message if no template found
        $message = $rendered['content'] ?? $type;

        $log = NotificationLog::create([
            'user_id'   => $userId,
            'channel'   => 'whatsapp',
            'type'      => $type,
            'recipient' => $to,
            'status'    => 'pending',
            'payload'   => ['to' => $to, 'type' => $type, 'variables' => $variables],
        ]);

        $result = $this->provider->sendText($to, $message);

        $log->update([
            'status'        => $result['success'] ? 'sent' : 'failed',
            'error_message' => $result['error'],
            'sent_at'       => $result['success'] ? now() : null,
        ]);

        return $result['success'];
    }

    /**
     * Send a raw text message without a template.
     */
    public function sendRaw(string $to, string $message, ?int $userId = null): bool
    {
        $result = $this->provider->sendText($to, $message);

        NotificationLog::create([
            'user_id'       => $userId,
            'channel'       => 'whatsapp',
            'type'          => 'raw',
            'recipient'     => $to,
            'status'        => $result['success'] ? 'sent' : 'failed',
            'error_message' => $result['error'],
            'sent_at'       => $result['success'] ? now() : null,
        ]);

        return $result['success'];
    }

    private function resolveProvider(): WhatsAppProvider
    {
        return match (config('whatsapp.provider', 'log')) {
            'meta'   => new MetaWhatsAppProvider(),
            'twilio' => new TwilioWhatsAppProvider(),
            default  => new LogWhatsAppProvider(),
        };
    }
}
