<?php

namespace Tests\Concerns;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;

/**
 * Stripe TEST mode with no network: a fake `sk_test_` key, a fake HTTP client that answers like Stripe's test API (and records every
 * call), and webhooks signed with a test secret exactly as Stripe signs them.
 */
trait FakesStripe
{
    private string $stripeWebhookSecret = 'whsec_testsecret1234567890abcdef12';

    /** @var list<array{method: string, url: string, params: mixed}> */
    private array $stripeCalls = [];

    protected function fakeStripe(): void
    {
        config(['services.stripe.secret' => 'sk_test_'.str_repeat('x', 24), 'services.stripe.webhook_secret' => $this->stripeWebhookSecret]);

        $calls = &$this->stripeCalls;
        ApiRequestor::setHttpClient(new class($calls) implements ClientInterface {
            /** @param list<array{method: string, url: string, params: mixed}> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->calls[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];
                $id = 'cs_test_'.substr(md5((string) count($this->calls).microtime()), 0, 16);

                return [json_encode(['id' => $id, 'object' => 'checkout.session', 'url' => "https://checkout.stripe.test/c/pay/{$id}", 'livemode' => false]), 200, []];
            }
        });
    }

    protected function restoreStripe(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
    }

    /** A signed checkout webhook, exactly as Stripe would send it. */
    protected function stripeWebhook(string $type, string $sessionId, array $meta, array $object = []): \Illuminate\Testing\TestResponse
    {
        $event = ['id' => 'evt_test_'.uniqid(), 'type' => $type, 'data' => ['object' => array_merge([
            'id' => $sessionId, 'object' => 'checkout.session', 'metadata' => ['flow' => 'order'] + $meta,
        ], $object)]];

        $this->app['auth']->forgetGuards();
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->stripeWebhookSecret);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"], $payload);
    }

    /** @return array<string, string> */
    protected function paidObject(): array
    {
        return ['payment_status' => 'paid', 'status' => 'complete', 'payment_intent' => 'pi_test_'.uniqid()];
    }
}
