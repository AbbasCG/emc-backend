<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twilio WhatsApp provider.
 *
 * Required env vars:
 *   WHATSAPP_PROVIDER=twilio
 *   WHATSAPP_TWILIO_SID=
 *   WHATSAPP_TWILIO_TOKEN=
 *   WHATSAPP_TWILIO_FROM=whatsapp:+14155238886
 */
class TwilioWhatsAppProvider implements WhatsAppProvider
{
    private string $sid;

    private string $token;

    private string $from;

    public function __construct()
    {
        $this->sid   = config('whatsapp.twilio.sid', '');
        $this->token = config('whatsapp.twilio.token', '');
        $this->from  = config('whatsapp.twilio.from', '');
    }

    public function sendText(string $to, string $message): array
    {
        $response = Http::withBasicAuth($this->sid, $this->token)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json", [
                'From' => $this->from,
                'To'   => 'whatsapp:' . ltrim($to, 'whatsapp:'),
                'Body' => $message,
            ]);

        if ($response->successful()) {
            return ['success' => true, 'provider_id' => $response->json('sid'), 'error' => null];
        }

        $error = $response->json('message') ?? 'Unknown Twilio error';
        Log::error('[WhatsApp:twilio] Send failed', ['status' => $response->status(), 'error' => $error]);

        return ['success' => false, 'provider_id' => null, 'error' => $error];
    }

    public function sendTemplate(string $to, string $templateName, array $components = [], string $language = 'en'): array
    {
        // Twilio uses content templates differently; fall back to text for now
        return $this->sendText($to, $templateName);
    }
}
