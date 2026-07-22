<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Local / dev / test provider. Writes to the log, sends nothing real.
 * Active automatically when SMS_PROVIDER=log (default).
 */
class LogSmsProvider implements SmsProvider
{
    public function sendText(string $to, string $message): array
    {
        Log::channel('daily')->info('[SMS:log] Text message', [
            'to' => $to, 'message' => $message,
        ]);

        return ['success' => true, 'provider_id' => 'log-' . uniqid(), 'error' => null];
    }
}
