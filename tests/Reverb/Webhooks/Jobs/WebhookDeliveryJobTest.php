<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Webhooks\Jobs;

use Hypervel\Http\Client\RequestException;
use Hypervel\Reverb\Webhooks\Events\WebhookFailed;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Reverb\Webhooks\WebhookPayload;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Reverb\ReverbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class WebhookDeliveryJobTest extends ReverbTestCase
{
    public function testSendsHttpRequestWithCorrectPayload(): void
    {
        Http::fake([
            'example.com/webhook' => Http::response('', 200),
        ]);

        $payload = new WebhookPayload(
            webhookId: 'test-webhook-id',
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

    public function testSignsPayloadWithAppSecretAndIncludesPusherHeaders(): void
    {
        Http::fake([
            'example.com/webhook' => Http::response('', 200),
        ]);

        $payload = new WebhookPayload(
            webhookId: 'test-webhook-id',
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

    public function testCustomHeadersMergedWithFrameworkHeaders(): void
    {
        Http::fake([
            'example.com/webhook' => Http::response('', 200),
        ]);

        $payload = new WebhookPayload(
            webhookId: 'test-webhook-id',
            timeMs: 1712000000000,
            events: [['name' => 'channel_occupied', 'channel' => 'test-channel']],
        );

        $job = new WebhookDeliveryJob(
            $payload,
            'https://example.com/webhook',
            'app-key',
            'app-secret',
            headers: ['Authorization' => 'Bearer test-token', 'X-Custom' => 'value'],
        );
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->header('Authorization')[0] === 'Bearer test-token'
                && $request->header('X-Custom')[0] === 'value'
                && $request->header('X-Pusher-Key')[0] === 'app-key';
        });
    }

    public function testFrameworkHeadersCannotBeOverriddenCaseInsensitive(): void
    {
        Http::fake([
            'example.com/webhook' => Http::response('', 200),
        ]);

        $payload = new WebhookPayload(
            webhookId: 'test-webhook-id',
            timeMs: 1712000000000,
            events: [['name' => 'channel_occupied', 'channel' => 'test-channel']],
        );

        $job = new WebhookDeliveryJob(
            $payload,
            'https://example.com/webhook',
            'real-key',
            'real-secret',
            headers: [
                'x-pusher-key' => 'evil-key',
                'X-PUSHER-SIGNATURE' => 'evil-sig',
                'content-type' => 'text/plain',
            ],
        );
        $job->handle();

        Http::assertSent(function ($request) use ($payload) {
            $body = $payload->toJson();
            $expectedSignature = hash_hmac('sha256', $body, 'real-secret');

            return $request->header('X-Pusher-Key')[0] === 'real-key'
                && $request->header('X-Pusher-Signature')[0] === $expectedSignature;
        });
    }

    public function testWebhookIdPresentInPayload(): void
    {
        Http::fake([
            'example.com/webhook' => Http::response('', 200),
        ]);

        $payload = new WebhookPayload(
            webhookId: 'my-unique-webhook-id',
            timeMs: 1712000000000,
            events: [['name' => 'channel_occupied', 'channel' => 'test-channel']],
        );

        $job = new WebhookDeliveryJob($payload, 'https://example.com/webhook', 'app-key', 'app-secret');
        $job->handle();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['webhook_id'] === 'my-unique-webhook-id';
        });
    }

    public function testDispatchesWebhookFailedEventOnFinalFailure(): void
    {
        Event::fake([WebhookFailed::class]);

        $payload = new WebhookPayload(
            webhookId: 'test-webhook-id',
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

    public function testThrowsOnHttpFailure(): void
    {
        Http::fake([
            'example.com/webhook' => Http::response('Internal Server Error', 500),
        ]);

        $payload = new WebhookPayload(
            webhookId: 'test-webhook-id',
            timeMs: 1712000000000,
            events: [['name' => 'channel_occupied', 'channel' => 'test-channel']],
        );

        $job = new WebhookDeliveryJob($payload, 'https://example.com/webhook', 'app-key', 'app-secret');

        $this->expectException(RequestException::class);

        $job->handle();
    }

    #[DataProvider('redirectResponses')]
    public function testRedirectResponsesAreNotFollowedAndThrow(int $status, array $headers): void
    {
        Http::fake([
            'example.com/webhook' => Http::response('', $status, $headers),
            'example.com/moved' => Http::response('', 200),
        ]);

        $payload = new WebhookPayload(
            webhookId: 'test-webhook-id',
            timeMs: 1712000000000,
            events: [['name' => 'channel_occupied', 'channel' => 'test-channel']],
        );

        $job = new WebhookDeliveryJob($payload, 'https://example.com/webhook', 'app-key', 'app-secret');

        try {
            $job->handle();
            $this->fail('Expected the redirect response to fail the delivery.');
        } catch (RequestException $exception) {
            $this->assertSame($status, $exception->response->status());
        }

        Http::assertSentCount(1);
    }

    public static function redirectResponses(): array
    {
        return [
            'redirect with location' => [302, ['Location' => 'https://example.com/moved']],
            'redirect without location' => [300, []],
        ];
    }

    public function testRetriesCountAttemptsAfterTheFirst(): void
    {
        $payload = new WebhookPayload(
            webhookId: 'test-webhook-id',
            timeMs: 1712000000000,
            events: [['name' => 'channel_occupied', 'channel' => 'test-channel']],
        );

        $this->assertSame(4, (new WebhookDeliveryJob($payload, 'https://example.com/webhook', 'app-key', 'app-secret'))->tries);
        $this->assertSame(1, (new WebhookDeliveryJob($payload, 'https://example.com/webhook', 'app-key', 'app-secret', retries: 0))->tries);
    }
}
