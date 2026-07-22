<?php

namespace App\Services\Sms;

use App\Models\NotificationLog;
use App\Models\NotificationTemplate;

/**
 * High-level SMS notification facade — mirrors WhatsAppNotificationService's
 * resolve-provider / render-template / log pattern exactly, per the
 * instruction to reuse the existing architecture rather than invent a
 * parallel one.
 */
class SmsNotificationService
{
    private SmsProvider $provider;

    public function __construct()
    {
        $this->provider = $this->resolveProvider();
    }

    public static function isValidE164(?string $phone): bool
    {
        return $phone !== null && (bool) preg_match('/^\+[1-9]\d{7,14}$/', $phone);
    }

    public function send(string $to, string $type, array $variables = [], ?int $userId = null): bool
    {
        if (! self::isValidE164($to)) {
            NotificationLog::create([
                'user_id' => $userId, 'channel' => 'sms', 'type' => $type, 'recipient' => $to,
                'status' => 'failed', 'error_message' => 'Invalid or missing E.164 phone number.',
            ]);

            return false;
        }

        $rendered = NotificationTemplate::render($type, 'sms', $variables);
        $message = $rendered['content'] ?? $type;

        $log = NotificationLog::create([
            'user_id' => $userId, 'channel' => 'sms', 'type' => $type, 'recipient' => $to,
            'status' => 'pending', 'payload' => ['to' => $to, 'type' => $type, 'variables' => $variables],
        ]);

        $result = $this->provider->sendText($to, $message);

        $log->update([
            'status'        => $result['success'] ? 'sent' : 'failed',
            'error_message' => $result['error'],
            'sent_at'       => $result['success'] ? now() : null,
        ]);

        return $result['success'];
    }

    private function resolveProvider(): SmsProvider
    {
        return match (config('sms.provider', 'log')) {
            'twilio' => new TwilioSmsProvider(),
            default  => new LogSmsProvider(),
        };
    }
}
