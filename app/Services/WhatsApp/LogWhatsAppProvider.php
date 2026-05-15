<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Local / dev / test provider.
 *
 * Writes all outgoing messages to the Laravel log.
 * Does NOT send any real messages.
 * Configured automatically when WHATSAPP_PROVIDER=log (default).
 */
class LogWhatsAppProvider implements WhatsAppProvider
{
    public function sendText(string $to, string $message): array
    {
        Log::channel('daily')->info('[WhatsApp:log] Text message', [
            'to'      => $to,
            'message' => $message,
        ]);

        return ['success' => true, 'provider_id' => 'log-' . uniqid(), 'error' => null];
    }

    public function sendTemplate(string $to, string $templateName, array $components = [], string $language = 'en'): array
    {
        Log::channel('daily')->info('[WhatsApp:log] Template message', [
            'to'           => $to,
            'template'     => $templateName,
            'language'     => $language,
            'components'   => $components,
        ]);

        return ['success' => true, 'provider_id' => 'log-' . uniqid(), 'error' => null];
    }
}
