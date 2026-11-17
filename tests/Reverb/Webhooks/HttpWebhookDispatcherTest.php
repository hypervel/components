<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Webhooks;

use Hypervel\Contracts\Bus\Dispatcher as BusDispatcher;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Reverb\Application;
use Hypervel\Reverb\Webhooks\Contracts\WebhookSender;
use Hypervel\Reverb\Webhooks\HttpWebhookDispatcher;
use Hypervel\Reverb\Webhooks\Jobs\FlushWebhookBatchJob;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Reverb\Webhooks\WebhookBatchBuffer;
use Hypervel\Reverb\Webhooks\WebhookPayload;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class HttpWebhookDispatcherTest extends ReverbTestCase
{
    public function testDispatchesJobForAllowedEvent(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
        ]));

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

    public function testSkipsDispatchForDisallowedEvent(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'member_added', ['channel' => 'test-channel']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testSkipsWhenNoWebhookConfigured(): void
    {
        Queue::fake();

        $app = $this->makeApp();

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testJobUsesRedisConnectionAndDedicatedQueue(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->connection === 'redis'
                && $job->queue === 'reverb-webhooks';
        });
    }

    public function testDispatchesForAllEventsWhenAllowlistIsEmpty(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => [],
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'member_added', ['channel' => 'presence-chat', 'user_id' => '42']);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'member_added'
                && $job->payload->events[0]['user_id'] === '42';
        });
    }

    public function testClientEventIncludesSocketIdAndStringifiedData(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['client_event'],
        ]));
        $connection = new FakeConnection;

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

    public function testChannelFilterSkipsNonMatchingChannel(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => array_replace($this->webhookConfig()['filter'], [
                'channel_name_starts_with' => 'tenant-1-',
            ]),
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-2-chat']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testChannelFilterAllowsMatchingChannel(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => array_replace($this->webhookConfig()['filter'], [
                'channel_name_starts_with' => 'tenant-1-',
            ]),
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-1-chat']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function testChannelFilterDisabledWhenNull(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => array_replace($this->webhookConfig()['filter'], [
                'channel_name_starts_with' => null,
            ]),
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'any-channel']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function testChannelEndsWithFilterSkipsNonMatchingChannel(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => array_replace($this->webhookConfig()['filter'], [
                'channel_name_ends_with' => '-chat',
            ]),
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-1-notifications']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testChannelEndsWithFilterAllowsMatchingChannel(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => array_replace($this->webhookConfig()['filter'], [
                'channel_name_ends_with' => '-chat',
            ]),
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-1-chat']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function testChannelEndsWithFilterDisabledWhenNull(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => array_replace($this->webhookConfig()['filter'], [
                'channel_name_ends_with' => null,
            ]),
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'any-channel']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function testBothFiltersAppliedAsAnd(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => [
                'channel_name_starts_with' => 'tenant-1-',
                'channel_name_ends_with' => '-chat',
            ],
        ]));

        $dispatcher = new HttpWebhookDispatcher;

        // Matches both — should pass
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-1-chat']);
        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function testBothFiltersRejectWhenOnlyPrefixMatches(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => [
                'channel_name_starts_with' => 'tenant-1-',
                'channel_name_ends_with' => '-chat',
            ],
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-1-notifications']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testBothFiltersRejectWhenOnlySuffixMatches(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'filter' => [
                'channel_name_starts_with' => 'tenant-1-',
                'channel_name_ends_with' => '-chat',
            ],
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'tenant-2-chat']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testCustomHeadersPassedToJob(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'headers' => ['Authorization' => 'Bearer test-token'],
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->headers === ['Authorization' => 'Bearer test-token'];
        });
    }

    public function testBatchingAppendsToBufferAndSchedulesFlush(): void
    {
        Queue::fake([FlushWebhookBatchJob::class]);

        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->expects('appendAndCheckSchedule')
            ->with('app-1', Argument::type('array'))
            ->returns(true);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'batching' => array_replace($this->webhookConfig()['batching'], [
                'enabled' => true,
                'max_delay_ms' => 250,
            ]),
        ]));

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

    public function testBatchingDoesNotScheduleFlushWhenLockAlreadyHeld(): void
    {
        Queue::fake([FlushWebhookBatchJob::class]);

        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->expects('appendAndCheckSchedule')
            ->returns(false);
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'batching' => array_replace($this->webhookConfig()['batching'], [
                'enabled' => true,
            ]),
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
        Queue::assertNotPushed(FlushWebhookBatchJob::class);
    }

    public function testBatchingClearsANewlyOwnedLockWhenQueueDispatchFails(): void
    {
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->expects('appendAndCheckSchedule')
            ->with('app-1', Argument::type('array'))
            ->returns(true);
        $buffer->expects('clearFlushLock')->with('app-1');
        $this->app->instance(WebhookBatchBuffer::class, $buffer);
        $failure = new RuntimeException('Queue dispatch failed.');
        $bus = Double::for(BusDispatcher::class)->strict();
        $bus->expects('dispatch')
            ->with(Argument::type(FlushWebhookBatchJob::class))
            ->throws($failure);
        $this->app->instance(BusDispatcher::class, $bus);
        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'batching' => array_replace($this->webhookConfig()['batching'], [
                'enabled' => true,
            ]),
        ]));
        $dispatcher = new HttpWebhookDispatcher;

        $exceptionHandler = Double::for(ExceptionHandler::class)->strict();
        $exceptionHandler->expects('report')->with($failure);
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);

        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testBufferFailuresAreReportedAndCancellationPropagates(bool $cancel): void
    {
        $failure = $cancel ? new CanceledException : new RuntimeException('Buffer unavailable.');
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->expects('appendAndCheckSchedule')->throws($failure);
        $buffer->expects('clearFlushLock')->never();
        $this->app->instance(WebhookBatchBuffer::class, $buffer);

        $exceptionHandler = Double::for(ExceptionHandler::class)->strict();
        if ($cancel) {
            $exceptionHandler->expects('report')->never();
            $this->expectExceptionObject($failure);
        } else {
            $exceptionHandler->expects('report')->with($failure);
        }
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);

        $webhooks = $this->webhookConfig();
        $webhooks['url'] = 'https://example.com/webhook';
        $webhooks['events'] = ['channel_occupied'];
        $webhooks['batching']['enabled'] = true;

        (new HttpWebhookDispatcher)->dispatch($this->makeApp(webhooks: $webhooks), 'channel_occupied');
    }

    public function testBatchingDoesNotClearALockOwnedByAnotherDispatch(): void
    {
        $buffer = Double::for(WebhookBatchBuffer::class)->strict();
        $buffer->expects('appendAndCheckSchedule')->returns(false);
        $buffer->expects('clearFlushLock')->never();
        $this->app->instance(WebhookBatchBuffer::class, $buffer);
        $bus = Double::for(BusDispatcher::class)->strict();
        $bus->expects('dispatch')->never();
        $this->app->instance(BusDispatcher::class, $bus);
        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'batching' => array_replace($this->webhookConfig()['batching'], [
                'enabled' => true,
            ]),
        ]));

        (new HttpWebhookDispatcher)->dispatch(
            $app,
            'channel_occupied',
            ['channel' => 'test-channel'],
        );
    }

    public function testImmediateDispatchWhenBatchingDisabled(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'batching' => array_replace($this->webhookConfig()['batching'], [
                'enabled' => false,
            ]),
        ]));

        $dispatcher = new HttpWebhookDispatcher;
        $dispatcher->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        Queue::assertPushed(WebhookDeliveryJob::class);
    }

    public function testImmediateWebhooksAreHandedToTheBoundSender(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
        ]));
        $sender = Double::for(WebhookSender::class)->strict();
        $sender->expects('send')->with(
            $app,
            $app->webhooks(),
            Argument::satisfies(static fn (WebhookPayload $payload): bool => $payload->events === [
                ['name' => 'channel_occupied', 'channel' => 'test-channel'],
            ]),
        );
        $this->app->instance(WebhookSender::class, $sender);

        (new HttpWebhookDispatcher)->dispatch($app, 'channel_occupied', ['channel' => 'test-channel']);

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testSubscriptionCountEventIncludesCountInPayload(): void
    {
        Queue::fake();

        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => [],
        ]));

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

    public function testSubscriptionCountBypassesEventsAllowlist(): void
    {
        Queue::fake();

        // Events list only has channel_occupied — subscription_count is NOT listed
        $app = $this->makeApp(webhooks: array_replace($this->webhookConfig(), [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
        ]));

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
