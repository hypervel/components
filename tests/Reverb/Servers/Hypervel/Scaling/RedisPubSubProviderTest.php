<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Reverb\Contracts\Logger;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubIncomingMessageHandler;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisPubSubProvider;
use Hypervel\Support\Sleep;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JsonException;
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

    public function testPublishUsesIndependentRedisConnectionWithoutSubscriber(): void
    {
        $redis = m::mock(RedisProxy::class);
        $redis->expects('publish')->with('reverb', '{"id":1}')->andReturn(3);
        $provider = $this->provider($redis);

        $this->assertSame(3, $provider->publish(['id' => 1]));
        $this->assertNull($provider->subscriberForTest());
    }

    public function testPublishFailureDoesNotRetainThePayload(): void
    {
        $failure = new RuntimeException('redis unavailable');
        $redis = m::mock(RedisProxy::class);
        $redis->expects('publish')
            ->with('reverb', '{"id":1}')
            ->andThrow($failure);
        $redis->expects('publish')
            ->with('reverb', '{"id":2}')
            ->andReturn(1);
        $provider = $this->provider($redis);

        try {
            $provider->publish(['id' => 1]);
            $this->fail('Expected Redis publishing to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(1, $provider->publish(['id' => 2]));
    }

    public function testPublishPropagatesEncodingFailureWithoutCallingRedis(): void
    {
        $redis = m::mock(RedisProxy::class);
        $redis->shouldNotReceive('publish');
        $provider = $this->provider($redis);

        $this->expectException(JsonException::class);

        $provider->publish(['invalid' => NAN]);
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

    protected function reconnect(): void
    {
        ++$this->reconnectCount;
    }
}
