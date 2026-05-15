<?php

namespace App\Services\WhatsApp;

interface WhatsAppProvider
{
    /**
     * Send a text message to a phone number.
     *
     * @param  string  $to      Phone in E.164 format: +966512345678
     * @param  string  $message Plain text message body.
     * @return array{success: bool, provider_id: string|null, error: string|null}
     */
    public function sendText(string $to, string $message): array;

    /**
     * Send a template message (provider-specific template name and language).
     *
     * @param  string  $to
     * @param  string  $templateName
     * @param  array   $components   Template variable substitutions.
     * @param  string  $language     e.g. 'en_US', 'ar'
     */
    public function sendTemplate(string $to, string $templateName, array $components = [], string $language = 'en'): array;
}
