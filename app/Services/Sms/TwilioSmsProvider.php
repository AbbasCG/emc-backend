<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twilio SMS provider — uses its own dedicated credentials
 * (config('sms.twilio.*')), deliberately separate from the WhatsApp Twilio
 * config (config('whatsapp.twilio.*')): a Twilio account authorized for the
 * WhatsApp sandbox/API is not automatically authorized to send plain SMS.
 *
 * Required env vars:
 *   SMS_PROVIDER=twilio
 *   SMS_TWILIO_SID=
 *   SMS_TWILIO_TOKEN=
 *   SMS_TWILIO_FROM=+15551234567
 */
class TwilioSmsProvider implements SmsProvider
{
    private string $sid;

    private string $token;

    private string $from;

    public function __construct()
    {
        $this->sid   = config('sms.twilio.sid', '');
        $this->token = config('sms.twilio.token', '');
        $this->from  = config('sms.twilio.from', '');
    }

    public function sendText(string $to, string $message): array
    {
        if (! $this->sid || ! $this->token || ! $this->from) {
            Log::warning('[SMS:twilio] Not configured — refusing to send.', ['to' => $to]);

            return ['success' => false, 'provider_id' => null, 'error' => 'SMS provider not configured'];
        }

        $response = Http::withBasicAuth($this->sid, $this->token)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json", [
                'From' => $this->from,
                'To'   => $to,
                'Body' => $message,
            ]);

        if ($response->successful()) {
            return ['success' => true, 'provider_id' => $response->json('sid'), 'error' => null];
        }

        $error = $response->json('message') ?? 'Unknown Twilio error';
        Log::error('[SMS:twilio] Send failed', ['status' => $response->status(), 'error' => $error]);

        return ['success' => false, 'provider_id' => null, 'error' => $error];
    }
}
