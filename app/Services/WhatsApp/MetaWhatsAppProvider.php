<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API provider.
 *
 * Docs: https://developers.facebook.com/docs/whatsapp/cloud-api
 *
 * Required env vars:
 *   WHATSAPP_PROVIDER=meta
 *   WHATSAPP_META_TOKEN=<permanent access token>
 *   WHATSAPP_META_PHONE_NUMBER_ID=<phone number ID from Meta dashboard>
 */
class MetaWhatsAppProvider implements WhatsAppProvider
{
    private string $baseUrl;

    private string $phoneNumberId;

    private string $token;

    private string $apiVersion;

    public function __construct()
    {
        $this->token         = config('whatsapp.meta.token', '');
        $this->phoneNumberId = config('whatsapp.meta.phone_number_id', '');
        $this->apiVersion    = config('whatsapp.meta.api_version', 'v19.0');
        $this->baseUrl       = config('whatsapp.meta.base_url', 'https://graph.facebook.com');
    }

    public function sendText(string $to, string $message): array
    {
        $response = Http::withToken($this->token)
            ->post("{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['preview_url' => false, 'body' => $message],
            ]);

        return $this->parseResponse($response);
    }

    public function sendTemplate(string $to, string $templateName, array $components = [], string $language = 'en'): array
    {
        $response = Http::withToken($this->token)
            ->post("{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'template',
                'template'          => [
                    'name'       => $templateName,
                    'language'   => ['code' => $language],
                    'components' => $components,
                ],
            ]);

        return $this->parseResponse($response);
    }

    private function parseResponse(\Illuminate\Http\Client\Response $response): array
    {
        if ($response->successful()) {
            $data = $response->json();
            $providerId = $data['messages'][0]['id'] ?? null;

            return ['success' => true, 'provider_id' => $providerId, 'error' => null];
        }

        $error = $response->json('error.message') ?? 'Unknown Meta API error';
        Log::error('[WhatsApp:meta] Send failed', ['status' => $response->status(), 'error' => $error]);

        return ['success' => false, 'provider_id' => null, 'error' => $error];
    }
}
