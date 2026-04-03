<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Webhooks\Jobs;

use Hypervel\Reverb\Webhooks\Events\WebhookFailed;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Reverb\Webhooks\WebhookPayload;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Reverb\ReverbTestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class WebhookDeliveryJobTest extends ReverbTestCase
{
    public function testSendsHttpRequestWithCorrectPayload()
    {
        Http::fake([
            'example.com/webhook' => Http::response('', 200),
        ]);

        $payload = new WebhookPayload(
            timeMs: 1712000000000,
            events: [['name' => 'channel_occupied', 'channel' => 'test-channel']],
        );

        $job = new WebhookDeliveryJob($payload, 'https://example.com/webhook', 'app-key', 'app-secret');
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/webhook'
                && $request->method() === 'POST'
                && $request->header('Content-Type')[0] === 'application/json';
        });
    }

    public function testSignsPayloadWithAppSecretAndIncludesPusherHeaders()
    {
        Http::fake([
            'example.com/webhook' => Http::response('', 200),
        ]);

        $payload = new WebhookPayload(
            timeMs: 1712000000000,
            events: [['name' => 'channel_occupied', 'channel' => 'test-channel']],
        );

        $job = new WebhookDeliveryJob($payload, 'https://example.com/webhook', 'my-key', 'my-secret');
        $job->handle();

        Http::assertSent(function ($request) use ($payload) {
            $body = $payload->toJson();
            $expectedSignature = hash_hmac('sha256', $body, 'my-secret');

            return $request->header('X-Pusher-Key')[0] === 'my-key'
                && $request->header('X-Pusher-Signature')[0] === $expectedSignature;
        });
    }

    public function testDispatchesWebhookFailedEventOnFinalFailure()
    {
        Event::fake([WebhookFailed::class]);

        $payload = new WebhookPayload(
            timeMs: 1712000000000,
            events: [['name' => 'channel_occupied', 'channel' => 'test-channel']],
        );

        $job = new WebhookDeliveryJob($payload, 'https://example.com/webhook', 'app-key', 'app-secret');
        $job->failed(new RuntimeException('Connection timed out'));

        Event::assertDispatched(WebhookFailed::class, function (WebhookFailed $event) {
            return $event->payload->events[0]['name'] === 'channel_occupied'
                && $event->url === 'https://example.com/webhook'
                && $event->exception->getMessage() === 'Connection timed out';
        });
    }
}
