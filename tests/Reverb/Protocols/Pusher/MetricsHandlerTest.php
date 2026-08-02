<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;
use Hypervel\Reverb\Protocols\Pusher\PendingMetric;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\MetricsRequestPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\MetricsResponsePipeMessage;
use Hypervel\Support\Facades\Log;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\Channel as CoroutineChannel;
use Swoole\Server;

class MetricsHandlerTest extends ReverbTestCase
{
    protected MetricsHandler $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $serverManager = m::mock(ServerProviderManager::class);
        $serverManager->shouldReceive('subscribesToEvents')->andReturn(false);

        $pubSub = m::mock(PubSubProvider::class);

        $this->metrics = new MetricsHandler(
            $serverManager,
            $this->app->make(ChannelManager::class),
            $pubSub,
            $this->app->make(Server::class),
        );
    }

    public function testGatherConnectionsCount(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-two');

        $result = $this->metrics->gather($app, 'connections');

        $this->assertSame(['count' => 2], $result);
    }

    public function testGatherConnectionsDeduplicatesSameConnectionOnMultipleChannels(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $connection = $this->subscribeConnection('test-channel-one');

        // Subscribe same connection to a second channel
        $channel = $this->channels()->findOrCreate('test-channel-two');
        $channel->subscribe($connection);

        $result = $this->metrics->gather($app, 'connections');

        $this->assertSame(['count' => 1], $result);
    }

    public function testGatherConnectionsReturnsEmptyWhenNoConnections(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $result = $this->metrics->gather($app, 'connections');

        $this->assertSame(['count' => 0], $result);
    }

    public function testSingleWorkerGatherRetainsNoPendingState(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();
        $serverManager = m::mock(ServerProviderManager::class);
        $serverManager->expects('subscribesToEvents')->andReturnFalse();
        $handler = new MetricsHandlerProbe(
            $serverManager,
            $this->app->make(ChannelManager::class),
            m::mock(PubSubProvider::class),
            $this->app->make(Server::class),
        );

        $this->assertSame(['count' => 0], $handler->gather($app, 'connections'));
        $this->assertSame([], $handler->metricsForTest());
        $this->assertSame([], $handler->waitersForTest());
    }

    public function testGatherChannelsReturnsOccupiedChannels(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-two');

        $result = $this->metrics->gather($app, 'channels');

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('test-channel-one', $result);
        $this->assertArrayHasKey('test-channel-two', $result);
    }

    public function testGatherChannelsWithSubscriptionCountInfo(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-two');

        $result = $this->metrics->gather($app, 'channels', ['info' => 'subscription_count']);

        $this->assertSame(2, $result['test-channel-one']['subscription_count']);
        $this->assertSame(1, $result['test-channel-two']['subscription_count']);
    }

    public function testGatherChannelsWithPrefixFilter(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('presence-test-channel', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);

        $result = $this->metrics->gather($app, 'channels', ['filter' => 'presence-']);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('presence-test-channel', $result);
        $this->assertArrayNotHasKey('test-channel-one', $result);
    }

    public function testGatherChannelsExcludesEmptyChannels(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $connection = $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-two');

        // Unsubscribe from channel one so it's empty
        $this->channels()->find('test-channel-one')->unsubscribe($connection);

        $result = $this->metrics->gather($app, 'channels');

        $this->assertCount(1, $result);
        $this->assertArrayNotHasKey('test-channel-one', $result);
        $this->assertArrayHasKey('test-channel-two', $result);
    }

    public function testGatherChannelsWithUserCountForPresence(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('presence-test-channel', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);
        $this->subscribeConnection('presence-test-channel', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);
        $this->subscribeConnection('test-channel');

        $result = $this->metrics->gather($app, 'channels', ['info' => 'user_count']);

        $this->assertSame(1, $result['presence-test-channel']['user_count']);
        // Non-presence channels don't have user_count
        $this->assertSame([], $result['test-channel']);
    }

    public function testGatherSingleChannelInfo(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-one');

        $result = $this->metrics->gather($app, 'channel', [
            'channel' => 'test-channel-one',
            'info' => 'occupied,subscription_count',
        ]);

        $this->assertTrue($result['occupied']);
        $this->assertSame(2, $result['subscription_count']);
    }

    public function testGatherSingleChannelReturnsUnoccupiedWhenNoConnections(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $result = $this->metrics->gather($app, 'channel', [
            'channel' => 'nonexistent-channel',
            'info' => 'occupied,subscription_count',
        ]);

        $this->assertFalse($result['occupied']);
        $this->assertArrayNotHasKey('subscription_count', $result);
    }

    public function testGatherSingleChannelCacheInfo(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('cache-test-channel');
        $this->channels()->find('cache-test-channel')->broadcast(['cached' => 'data']);

        $result = $this->metrics->gather($app, 'channel', [
            'channel' => 'cache-test-channel',
            'info' => 'occupied,subscription_count,cache',
        ]);

        $this->assertTrue($result['occupied']);
        $this->assertSame(1, $result['subscription_count']);
        $this->assertSame(['cached' => 'data'], $result['cache']);
    }

    public function testLocalMetricPayloadsContainOnlyTransportSafeValues(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('public-channel');
        $this->subscribeConnection('private-channel');
        $this->subscribeConnection('presence-channel', [
            'user_id' => 'user-one',
            'user_info' => [
                'name' => 'Taylor',
                'roles' => ['admin', 'editor'],
                'active' => true,
            ],
        ]);
        $this->subscribeConnection('cache-channel');
        $this->channels()->find('cache-channel')->broadcast([
            'event' => 'cached',
            'payload' => ['id' => 1, 'enabled' => true],
        ]);

        $payloads = [
            $this->metrics->gather($app, 'connections'),
            $this->metrics->gather($app, 'channel', [
                'channel' => 'presence-channel',
                'info' => 'occupied,subscription_count,user_count',
            ]),
            $this->metrics->gather($app, 'channels', [
                'info' => 'occupied,subscription_count,user_count,cache',
            ]),
            $this->metrics->gather($app, 'presence', ['channel' => 'presence-channel']),
        ];

        $this->assertIsInt($payloads[0]['count']);

        foreach ($payloads as $payload) {
            $this->assertTransportSafe($payload);
        }
    }

    // ── Scaling path tests (gatherMetricsFromSubscribers) ──────────────

    /**
     * Create a MetricsHandler wired for the scaling path.
     *
     * The mock PubSubProvider captures the on() callback, then when publish()
     * is called, it immediately invokes that callback with the given responses,
     * simulating subscribers that respond before the coroutine channel pop().
     */
    private function scalingMetricsHandler(array $subscriberResponses): MetricsHandler
    {
        $serverManager = m::mock(ServerProviderManager::class);
        $serverManager->shouldReceive('subscribesToEvents')->andReturn(true);

        $capturedCallback = null;

        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->shouldReceive('on')->once()->andReturnUsing(function (string $event, callable $callback) use (&$capturedCallback) {
            $capturedCallback = $callback;
        });
        $pubSub->shouldReceive('publish')->once()->andReturnUsing(function () use (&$capturedCallback, $subscriberResponses) {
            // Simulate each subscriber responding immediately
            foreach ($subscriberResponses as $response) {
                $capturedCallback(['payload' => $response]);
            }

            return count($subscriberResponses);
        });
        $pubSub->shouldReceive('stopListening')->once();

        return new MetricsHandler(
            $serverManager,
            $this->app->make(ChannelManager::class),
            $pubSub,
            $this->app->make(Server::class),
        );
    }

    public function testScalingGatherConnectionsMergesFromSubscribers(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $handler = $this->scalingMetricsHandler([
            ['count' => 2],
            ['count' => 1],
        ]);

        $result = $handler->gather($app, 'connections');

        $this->assertSame(['count' => 3], $result);
    }

    public function testScalingGatherChannelsMergesSubscriptionCounts(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        // Two subscribers each report the same channel with different subscription counts
        $handler = $this->scalingMetricsHandler([
            ['test-channel' => ['subscription_count' => 3, 'occupied' => true]],
            ['test-channel' => ['subscription_count' => 2, 'occupied' => true]],
        ]);

        $result = $handler->gather($app, 'channels', ['info' => 'subscription_count']);

        $this->assertSame(5, $result['test-channel']['subscription_count']);
        $this->assertTrue($result['test-channel']['occupied']);
    }

    public function testScalingGatherChannelsDeduplicatesUserCountsAcrossSubscribers(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();
        $handler = $this->scalingMetricsHandler([
            [
                'presence-test' => [
                    'occupied' => true,
                    'user_count' => 3,
                    'reverb_user_ids' => ['one', 'two', 'three'],
                ],
            ],
            [
                'presence-test' => [
                    'occupied' => true,
                    'user_count' => 2,
                    'reverb_user_ids' => ['three', 'four'],
                ],
            ],
        ]);

        $result = $handler->gather($app, 'channels', ['info' => 'user_count']);

        $this->assertSame(4, $result['presence-test']['user_count']);
        $this->assertArrayNotHasKey('reverb_user_ids', $result['presence-test']);
    }

    public function testScalingGatherSingleChannelMergesFromSubscribers(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $handler = $this->scalingMetricsHandler([
            ['occupied' => true, 'subscription_count' => 2],
            ['occupied' => false, 'subscription_count' => 1],
        ]);

        $result = $handler->gather($app, 'channel', [
            'channel' => 'test-channel',
            'info' => 'occupied,subscription_count',
        ]);

        // occupied should be OR'd (true || false = true)
        $this->assertTrue($result['occupied']);
        // subscription_count should be summed (2 + 1 = 3)
        $this->assertSame(3, $result['subscription_count']);
    }

    public function testScalingGatherPresenceMergesACompleteUniqueSnapshot(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();
        $handler = $this->scalingMetricsHandler([
            [
                'exists' => true,
                'presence' => true,
                'users' => [
                    ['user_id' => 'one', 'user_info' => ['name' => 'Taylor']],
                    ['user_id' => 'two', 'user_info' => ['name' => 'Abigail']],
                ],
            ],
            [
                'exists' => true,
                'presence' => true,
                'users' => [
                    ['user_id' => 'one', 'user_info' => ['name' => 'Taylor']],
                    ['user_id' => 'three', 'user_info' => ['name' => 'Nuno']],
                ],
            ],
        ]);

        $result = $handler->gather($app, 'presence', ['channel' => 'presence-test']);

        $this->assertTrue($result['exists']);
        $this->assertTrue($result['presence']);
        $this->assertSame(['one', 'two', 'three'], array_column($result['users'], 'user_id'));
    }

    public function testUnscaledMultiWorkerGatherIncludesLocalAndSiblingResponses(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();
        $serverManager = m::mock(ServerProviderManager::class);
        $serverManager->expects('subscribesToEvents')->andReturnFalse();
        $server = m::mock(Server::class);
        $server->setting = ['worker_num' => 3];
        $server->worker_id = 1;
        $handler = null;
        $server->expects('sendMessage')
            ->twice()
            ->with(m::type(MetricsRequestPipeMessage::class), m::type('int'))
            ->andReturnUsing(function (MetricsRequestPipeMessage $message, int $workerId) use (&$handler): bool {
                $handler->receive(new MetricsResponsePipeMessage(
                    $message->requestId,
                    ['count' => $workerId + 1],
                ));

                return true;
            });
        $handler = new MetricsHandlerProbe(
            $serverManager,
            $this->app->make(ChannelManager::class),
            m::mock(PubSubProvider::class),
            $server,
        );

        $result = $handler->gather($app, 'connections');

        $this->assertSame(['count' => 4], $result);
        $this->assertSame([], $handler->metricsForTest());
        $this->assertSame([], $handler->waitersForTest());
    }

    public function testUnscaledMultiWorkerTimeoutKeepsEveryReceivedSlice(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();
        $serverManager = m::mock(ServerProviderManager::class);
        $serverManager->expects('subscribesToEvents')->andReturnFalse();
        $server = m::mock(Server::class);
        $server->setting = ['worker_num' => 3];
        $server->worker_id = 0;
        $handler = null;
        Log::shouldReceive('warning')
            ->once()
            ->with('Timed out while gathering Reverb metrics.', [
                'type' => 'connections',
                'received' => 2,
                'expected' => 3,
            ]);
        $server->expects('sendMessage')
            ->twice()
            ->andReturnUsing(function (MetricsRequestPipeMessage $message, int $workerId) use (&$handler): bool {
                if ($workerId === 1) {
                    $handler->receive(new MetricsResponsePipeMessage(
                        $message->requestId,
                        ['count' => 1],
                    ));
                }

                return true;
            });
        $handler = new MetricsHandlerProbe(
            $serverManager,
            $this->app->make(ChannelManager::class),
            m::mock(PubSubProvider::class),
            $server,
        );
        $handler->timeoutImmediately = true;

        $this->assertSame(
            ['count' => 1],
            $handler->gather($app, 'connections'),
        );
        $this->assertSame([], $handler->metricsForTest());
        $this->assertSame([], $handler->waitersForTest());
    }

    public function testUnscaledMultiWorkerSendFailureRemovesPendingState(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();
        $serverManager = m::mock(ServerProviderManager::class);
        $serverManager->expects('subscribesToEvents')->andReturnFalse();
        $server = m::mock(Server::class);
        $server->setting = ['worker_num' => 4];
        $server->worker_id = 1;
        $workerIds = [];
        $server->expects('sendMessage')
            ->times(3)
            ->andReturnUsing(function (MetricsRequestPipeMessage $message, int $workerId) use (&$workerIds): bool {
                $workerIds[] = $workerId;

                return match ($workerId) {
                    0 => false,
                    2 => throw new RuntimeException('Later pipe failure.'),
                    default => true,
                };
            });
        $handler = new MetricsHandlerProbe(
            $serverManager,
            $this->app->make(ChannelManager::class),
            m::mock(PubSubProvider::class),
            $server,
        );

        try {
            $handler->gather($app, 'connections');
            $this->fail('Expected the sibling metrics request to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to request Reverb metrics from worker [0].', $exception->getMessage());
        }

        $this->assertSame([0, 2, 3], $workerIds);
        $this->assertSame([], $handler->metricsForTest());
        $this->assertSame([], $handler->waitersForTest());
    }

    public function testUnscaledMultiWorkerLocalMetricFailureRemovesPendingState(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();
        $failure = new RuntimeException('Unable to read local channels.');
        $serverManager = m::mock(ServerProviderManager::class);
        $serverManager->expects('subscribesToEvents')->andReturnFalse();
        $channels = m::mock(ChannelManager::class);
        $channels->expects('for')->with($app)->andThrow($failure);
        $server = m::mock(Server::class);
        $server->setting = ['worker_num' => 2];
        $server->worker_id = 0;
        $server->expects('sendMessage')->never();
        $handler = new MetricsHandlerProbe(
            $serverManager,
            $channels,
            m::mock(PubSubProvider::class),
            $server,
        );

        try {
            $handler->gather($app, 'connections');
            $this->fail('Expected local metric construction to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame([], $handler->metricsForTest());
        $this->assertSame([], $handler->waitersForTest());
    }

    public function testScalingResolvesImmediatelyWhenResponsesArriveBeforePop(): void
    {
        // Responses may arrive during publish(), before the handler knows how
        // many subscribers must respond.
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $handler = $this->scalingMetricsHandler([
            ['occupied' => true, 'subscription_count' => 5],
        ]);

        $result = $handler->gather($app, 'channel', [
            'channel' => 'test-channel',
            'info' => 'occupied,subscription_count',
        ]);

        $this->assertTrue($result['occupied']);
        $this->assertSame(5, $result['subscription_count']);
    }

    public function testScalingPublishFailureRemovesPendingMetricAndListener(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();
        $failure = new RuntimeException('Redis publish failed.');
        $serverManager = m::mock(ServerProviderManager::class);
        $serverManager->expects('subscribesToEvents')->andReturnTrue();
        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->expects('on');
        $pubSub->expects('publish')->andThrow($failure);
        $pubSub->expects('stopListening');
        $handler = new MetricsHandlerProbe(
            $serverManager,
            $this->app->make(ChannelManager::class),
            $pubSub,
            $this->app->make(Server::class),
        );

        try {
            $handler->gather($app, 'connections');
            $this->fail('Expected metrics publishing to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame([], $handler->metricsForTest());
    }

    public function testScalingPublishFailureRemainsPrimaryWhenListenerCleanupFails(): void
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();
        $failure = new RuntimeException('Redis publish failed.');
        $serverManager = m::mock(ServerProviderManager::class);
        $serverManager->expects('subscribesToEvents')->andReturnTrue();
        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->expects('on');
        $pubSub->expects('publish')->andThrow($failure);
        $pubSub->expects('stopListening')->andThrow(new RuntimeException('Listener cleanup failed.'));
        $handler = new MetricsHandlerProbe(
            $serverManager,
            $this->app->make(ChannelManager::class),
            $pubSub,
            $this->app->make(Server::class),
        );

        try {
            $handler->gather($app, 'connections');
            $this->fail('Expected metrics publishing to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame([], $handler->metricsForTest());
    }

    private function assertTransportSafe(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertTransportSafe($item);
            }

            return;
        }

        $this->assertTrue(
            is_scalar($value) || $value === null,
            sprintf('Unexpected metric payload value of type [%s].', get_debug_type($value)),
        );
    }
}

class MetricsHandlerProbe extends MetricsHandler
{
    public bool $timeoutImmediately = false;

    public function metricsForTest(): array
    {
        return $this->metrics;
    }

    public function waitersForTest(): array
    {
        return $this->waiters;
    }

    protected function waitForMetric(PendingMetric $metric, CoroutineChannel $waiter): array
    {
        if ($this->timeoutImmediately) {
            $waiter->close();
        }

        return parent::waitForMetric($metric, $waiter);
    }
}
