<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Engine\Channel;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Reverb\Contracts\Logger;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubIncomingMessageHandler;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisPubSubProvider;
use Hypervel\Support\Sleep;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;

class RedisPubSubProviderTest extends ReverbTestCase
{
    protected Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = m::mock(Logger::class);
        $this->logger->shouldReceive('info', 'error')->zeroOrMoreTimes();
        $this->app->instance(Logger::class, $this->logger);
        Log::flushState();
    }

    #[RunInSeparateProcess]
    public function testSpawnFailureRollsBackTheCommittedSubscriber(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);

        $subscriber = $this->subscriber();
        $subscriber->shouldReceive('subscribe')->once()->with('reverb');
        $subscriber->shouldReceive('close')->once()->andReturnUsing(function () use ($subscriber): void {
            $subscriber->closed = true;
        });
        $redis = m::mock(RedisProxy::class);
        $redis->shouldReceive('subscriber')->once()->andReturn($subscriber);
        $provider = $this->provider($redis);

        $provider->connect();

        $this->assertNull($provider->subscriberForTest());
        $this->assertSame(1, $provider->reconnectCount);
        $this->assertTrue($subscriber->closed);
    }

    public function testRepeatedConnectionFailuresReachTheRetryLimit(): void
    {
        Sleep::fake();
        $redis = m::mock(RedisProxy::class);
        $redis->shouldReceive('subscriber')
            ->times(60)
            ->andThrow(new RuntimeException('redis unavailable'));
        $provider = new RedisPubSubProvider(
            m::mock(PubSubIncomingMessageHandler::class),
            $redis,
            'reverb',
        );

        $provider->connect();

        Sleep::assertSleptTimes(59);
    }

    public function testDisconnectDuringSubscriptionDoesNotCommitOrSpawn(): void
    {
        $subscriber = $this->subscriber();
        $redis = m::mock(RedisProxy::class);
        $provider = $this->provider($redis);
        $subscriber->shouldReceive('subscribe')->once()->with('reverb')->andReturnUsing(
            function () use ($provider): void {
                $provider->disconnect();
            },
        );
        $subscriber->shouldReceive('close')->once()->andReturnUsing(function () use ($subscriber): void {
            $subscriber->closed = true;
        });
        $redis->shouldReceive('subscriber')->once()->andReturn($subscriber);

        $provider->connect();

        $this->assertNull($provider->subscriberForTest());
        $this->assertSame(0, $provider->reconnectCount);
        $this->assertTrue($subscriber->closed);
    }

    public function testQueuedPublishesDropInvalidJsonAndRetainTransientFailuresInOrder(): void
    {
        $firstSubscriber = $this->subscriber();
        $firstSubscriber->shouldReceive('subscribe')->once()->with('reverb');
        $firstSubscriber->shouldReceive('close')->once()->andReturnUsing(function () use ($firstSubscriber): void {
            $firstSubscriber->closed = true;
        });

        $secondSubscriber = $this->subscriber();
        $secondSubscriber->shouldReceive('subscribe')->once()->with('reverb');
        $secondSubscriber->shouldReceive('channel')->once()->andReturnUsing(static function (): Channel {
            $channel = new Channel(1);
            $channel->close();

            return $channel;
        });
        $secondSubscriber->shouldReceive('close')->once()->andReturnUsing(function () use ($secondSubscriber): void {
            $secondSubscriber->closed = true;
        });

        $redis = m::mock(RedisProxy::class);
        $redis->shouldReceive('subscriber')->twice()->andReturn($firstSubscriber, $secondSubscriber);
        $redis->shouldReceive('publish')
            ->once()
            ->with('reverb', '{"id":1}')
            ->andThrow(new RuntimeException('transient publish failure'));
        $redis->shouldReceive('publish')->once()->with('reverb', '{"id":1}')->andReturn(1);
        $redis->shouldReceive('publish')->once()->with('reverb', '{"id":2}')->andReturn(1);
        $provider = $this->provider($redis);
        $provider->publish(['invalid' => NAN]);
        $provider->publish(['id' => 1]);
        $provider->publish(['id' => 2]);

        $provider->connect();

        $this->assertSame([['id' => 1], ['id' => 2]], $provider->queuedPublishesForTest());

        $provider->connect();

        $this->assertSame([], $provider->queuedPublishesForTest());
        $this->assertTrue($firstSubscriber->closed);
        $this->assertTrue($secondSubscriber->closed);
    }

    protected function provider(RedisProxy $redis): RedisPubSubProviderProbe
    {
        return new RedisPubSubProviderProbe(
            m::mock(PubSubIncomingMessageHandler::class),
            $redis,
            'reverb',
        );
    }

    protected function subscriber(): Subscriber
    {
        $subscriber = m::mock(Subscriber::class);
        $subscriber->prefix = 'prefix:';
        $subscriber->closed = false;

        return $subscriber;
    }
}

class RedisPubSubProviderProbe extends RedisPubSubProvider
{
    public int $reconnectCount = 0;

    public function subscriberForTest(): ?Subscriber
    {
        return $this->subscriber;
    }

    public function queuedPublishesForTest(): array
    {
        return $this->queuedPublishes;
    }

    protected function reconnect(): void
    {
        ++$this->reconnectCount;
    }
}
