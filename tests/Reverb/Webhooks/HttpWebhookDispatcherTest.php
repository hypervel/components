<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Webhooks;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Webhooks\HttpWebhookDispatcher;
use Hypervel\Reverb\Webhooks\Jobs\FlushWebhookBatchJob;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

class HttpWebhookDispatcherTest extends ReverbTestCase
{
    public function testDispatchesJobForAllowedEvent()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: ['url' => 'https://example.com/webhook', 'events' => ['channel_occupied']]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->url === 'https://example.com/webhook'
                && $job->appKey === 'app-key'
                && $job->appSecret === 'app-secret'
                && $job->payload->webhookId !== ''
                && $job->payload->events[0]['name'] === 'channel_occupied'
                && $job->payload->events[0]['channel'] === 'test-channel'
                && $job->payload->timeMs > 0;
        });
    }

    public function testSkipsDispatchForDisallowedEvent()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: ['url' => 'https://example.com/webhook', 'events' => ['channel_occupied']]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'member_added', ['channel' => 'test-channel']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testSkipsWhenNoWebhookConfigured()
    {
        Queue::fake();

        $app = $this->makeApp();

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testJobUsesRedisConnectionAndDedicatedQueue()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: ['url' => 'https://example.com/webhook', 'events' => ['channel_occupied']]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->connection === 'redis'
                && $job->queue === 'reverb-webhooks';
        });
    }

    public function testDispatchesForAllEventsWhenAllowlistIsEmpty()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: ['url' => 'https://example.com/webhook', 'events' => []]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'member_added', ['channel' => 'presence-chat', 'user_id' => '42']);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'member_added'
                && $job->payload->events[0]['user_id'] === '42';
        });
    }

    public function testClientEventIncludesSocketIdAndStringifiedData()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: ['url' => 'https://example.com/webhook', 'events' => ['client_event']]);
        $connection = new \Hypervel\Tests\Reverb\Fixtures\FakeConnection;

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'client_event', [
            'event' => 'client-typing',
            'channel' => 'private-chat',
            'data' => ['user' => 'taylor'],
        ], $connection);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) use ($connection) {
            $event = $job->payload->events[0];

            return $event['name'] === 'client_event'
                && $event['channel'] === 'private-chat'
                && $event['event'] === 'client-typing'
                && $event['data'] === '{"user":"taylor"}'
                && $event['socket_id'] === $connection->id();
        });
    }

    public function testChannelFilterSkipsNonMatchingChannel()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => ['channel_name_starts_with' => 'tenant-1-'],
        ]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-2-chat']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testChannelFilterAllowsMatchingChannel()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => ['channel_name_starts_with' => 'tenant-1-'],
        ]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-1-chat']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function testChannelFilterDisabledWhenNull()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => ['channel_name_starts_with' => null],
        ]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'any-channel']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function testChannelEndsWithFilterSkipsNonMatchingChannel()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => ['channel_name_ends_with' => '-chat'],
        ]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-1-notifications']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testChannelEndsWithFilterAllowsMatchingChannel()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => ['channel_name_ends_with' => '-chat'],
        ]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-1-chat']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function testChannelEndsWithFilterDisabledWhenNull()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => ['channel_name_ends_with' => null],
        ]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'any-channel']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function testBothFiltersAppliedAsAnd()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => [
                'channel_name_starts_with' => 'tenant-1-',
                'channel_name_ends_with' => '-chat',
            ],
        ]);

        $dispatcher = new HttpWebhookDispatcher;

        // Matches both — should pass
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-1-chat']);
        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function testBothFiltersRejectWhenOnlyPrefixMatches()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => [
                'channel_name_starts_with' => 'tenant-1-',
                'channel_name_ends_with' => '-chat',
            ],
        ]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-1-notifications']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testBothFiltersRejectWhenOnlySuffixMatches()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => [
                'channel_name_starts_with' => 'tenant-1-',
                'channel_name_ends_with' => '-chat',
            ],
        ]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-2-chat']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testCustomHeadersPassedToJob()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'headers' => ['Authorization' => 'Bearer test-token'],
        ]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->headers === ['Authorization' => 'Bearer test-token'];
        });
    }

    public function testBatchingAppendsToBufferAndSchedulesFlush()
    {
        Queue::fake([FlushWebhookBatchJob::class]);

        $buffer = m::mock(WebhookBatchBuffer::class);
        $buffer->shouldReceive('appendAndCheckSchedule')
            ->once()
            ->with('app-1', m::type('array'))
            ->andReturn(true);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'batching' => ['enabled' => true, 'max_delay_ms' => 250],
        ]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        // WebhookDeliveryJob should NOT be dispatched immediately
        Queue::assertNotPushed(WebhookDeliveryJob::class);

        // FlushWebhookBatchJob should be dispatched (lock was acquired)
        Queue::assertPushed(FlushWebhookBatchJob::class, function (FlushWebhookBatchJob $job) {
            return $job->appId === 'app-1'
                && $job->queue === 'reverb-webhook-flush';
        });
    }

    public function testBatchingDoesNotScheduleFlushWhenLockAlreadyHeld()
    {
        Queue::fake([FlushWebhookBatchJob::class]);

        $buffer = m::mock(WebhookBatchBuffer::class);
        $buffer->shouldReceive('appendAndCheckSchedule')
            ->once()
            ->andReturn(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'batching' => ['enabled' => true],
        ]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
        Queue::assertNotPushed(FlushWebhookBatchJob::class);
    }

    public function testImmediateDispatchWhenBatchingDisabled()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'batching' => ['enabled' => false],
        ]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function testSubscriptionCountEventIncludesCountInPayload()
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: ['url' => 'https://example.com/webhook', 'events' => []]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'subscription_count', [
            'channel' => 'test-channel',
            'subscription_count' => 42,
        ]);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            $event = $job->payload->events[0];

            return $event['name'] === 'subscription_count'
                && $event['channel'] === 'test-channel'
                && $event['subscription_count'] === 42;
        });
    }

    public function testSubscriptionCountBypassesEventsAllowlist()
    {
        Queue::fake();

        // Events list only has channel_occupied — subscription_count is NOT listed
        $app = $this->makeApp(webhooks: ['url' => 'https://example.com/webhook', 'events' => ['channel_occupied']]);

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'subscription_count', [
            'channel' => 'test-channel',
            'subscription_count' => 5,
        ]);

        // Should still be dispatched — subscription_count bypasses the events filter
        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'subscription_count';
        });
    }

    /**
     * Create a test Application instance.
     */
    protected function makeApp(array $webhooks = []): Application
    {
        return new Application(
            'app-1',
            'app-key',
            'app-secret',
            60,
            30,
            ['*'],
            10_000,
            webhooks: $webhooks,
        );
    }
}
