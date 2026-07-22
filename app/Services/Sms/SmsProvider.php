<?php

namespace App\Services\Sms;

interface SmsProvider
{
    /**
     * @param  string  $to  Phone in E.164 format: +966512345678
     * @return array{success: bool, provider_id: string|null, error: string|null}
     */
    public function sendText(string $to, string $message): array;
}
