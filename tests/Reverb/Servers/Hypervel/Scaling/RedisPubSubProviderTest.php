<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\Subscriber\Message;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Reverb\Contracts\Logger;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubIncomingMessageHandler;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisPubSubProvider;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JsonException;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;

use function Hypervel\Coroutine\go;

class RedisPubSubProviderTest extends ReverbTestCase
{
    protected Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = m::spy(Logger::class);
        $this->app->instance(Logger::class, $this->logger);
        Log::flushState();
    }

    #[RunInSeparateProcess]
    public function testSpawnFailureRollsBackTheLifecycleOwner(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);

        $redis = m::mock(RedisProxy::class);
        $redis->shouldNotReceive('subscriber');
        $provider = $this->provider($redis);

        try {
            $provider->connect();
            $this->fail('Expected lifecycle coroutine creation to fail.');
        } catch (CoroutineCreateException) {
            $this->assertFalse($provider->runningForTest());
        }
    }

    public function testDuplicateConnectCallsRetainOneLifecycleOwner(): void
    {
        $messages = new Channel(1);
        $subscriber = $this->subscriber($messages);
        $subscriber->expects('subscribe')->with('reverb');
        $this->expectSubscriberClose($subscriber, $messages);
        $redis = m::mock(RedisProxy::class);
        $redis->expects('subscriber')->andReturn($subscriber);
        $provider = $this->provider($redis);

        $provider->connect();
        $provider->connect();

        $this->assertTrue($provider->runningForTest());

        $provider->disconnect();
        $this->waitUntilStopped($provider);
    }

    public function testConnectionFailuresContinuePastTheOldRetryLimitAndAreLoggedAtABoundedRate(): void
    {
        $redis = m::mock(RedisProxy::class);
        $provider = $this->provider($redis);
        $attempts = 0;
        $redis->expects('subscriber')->times(61)->andReturnUsing(
            function () use (&$attempts, $provider): never {
                ++$attempts;

                if ($attempts === 61) {
                    $provider->disconnect();
                }

                throw new RuntimeException('redis unavailable');
            },
        );

        $provider->connect();
        $this->waitUntilStopped($provider);

        $this->assertSame(61, $attempts);
        $this->logger->shouldHaveReceived('error')
            ->with('Redis connection failed: redis unavailable')
            ->twice();
    }

    public function testDisconnectDuringSubscriptionDoesNotCommitOrRetry(): void
    {
        $subscriber = $this->subscriber();
        $redis = m::mock(RedisProxy::class);
        $provider = $this->provider($redis);
        $subscriber->shouldReceive('subscribe')->once()->with('reverb')->andReturnUsing(
            function () use ($provider): void {
                $provider->disconnect();
            },
        );
        $this->expectSubscriberClose($subscriber);
        $redis->expects('subscriber')->andReturn($subscriber);

        $provider->connect();
        $this->waitUntilStopped($provider);

        $this->assertNull($provider->subscriberForTest());
        $this->assertTrue($subscriber->closed);
    }

    public function testTransportFailureClosesTheCommittedSubscriberAndRecovers(): void
    {
        $failedMessages = m::mock(Channel::class);
        $failedMessages->expects('pop')->andThrow(new RuntimeException('connection lost'));
        $failedSubscriber = $this->subscriber($failedMessages);
        $failedSubscriber->expects('subscribe')->with('reverb');
        $this->expectSubscriberClose($failedSubscriber);

        $recoveredSubscriber = $this->subscriber();
        $redis = m::mock(RedisProxy::class);
        $provider = $this->provider($redis);
        $recoveredSubscriber->expects('subscribe')->with('reverb')->andReturnUsing(
            function () use ($provider): void {
                $provider->disconnect();
            },
        );
        $this->expectSubscriberClose($recoveredSubscriber);
        $redis->expects('subscriber')->twice()->andReturn($failedSubscriber, $recoveredSubscriber);

        $provider->connect();
        $this->waitUntilStopped($provider);

        $this->assertTrue($failedSubscriber->closed);
        $this->assertTrue($recoveredSubscriber->closed);
        $this->logger->shouldHaveReceived('error')
            ->with('Redis connection failed: connection lost')
            ->once();
    }

    public function testMessageHandlerFailureDoesNotReplaceTheSubscriber(): void
    {
        $messages = new Channel(1);
        $messages->push(new Message('prefix:reverb', 'payload'));
        $subscriber = $this->subscriber($messages);
        $subscriber->expects('subscribe')->with('reverb');
        $redis = m::mock(RedisProxy::class);
        $redis->expects('subscriber')->andReturn($subscriber);
        $provider = $this->provider($redis);
        $this->expectSubscriberClose($subscriber, $messages);
        $handlerFailure = new RuntimeException('handler failed');
        $provider->messageHandlerForTest()
            ->expects('handle')
            ->with('payload')
            ->andReturnUsing(function () use ($provider, $handlerFailure): never {
                $provider->disconnect();

                throw $handlerFailure;
            });
        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->expects('report')->with($handlerFailure);
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);

        $provider->connect();
        $this->waitUntilStopped($provider);

        $this->logger->shouldNotHaveReceived('error', ['Redis connection failed: handler failed']);
    }

    public function testRetryWaitWakesWhenTheWorkerExits(): void
    {
        $provider = $this->provider(m::mock(RedisProxy::class));
        $provider->useRealRetryWait = true;
        $result = new Channel(1);

        go(fn () => $result->push($provider->waitBeforeRetryForTest()));
        usleep(1000);
        CoordinatorManager::until(Constants::WORKER_EXIT)->resume();

        $this->assertTrue($result->pop(1));
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

    protected function subscriber(?Channel $messages = null): Subscriber
    {
        $subscriber = m::mock(Subscriber::class);
        $subscriber->prefix = 'prefix:';
        $subscriber->closed = false;

        if ($messages !== null) {
            $subscriber->allows('channel')->andReturn($messages);
        }

        return $subscriber;
    }

    protected function expectSubscriberClose(Subscriber $subscriber, ?Channel $messages = null): void
    {
        $subscriber->expects('close')->andReturnUsing(function () use ($subscriber, $messages): void {
            $subscriber->closed = true;
            $messages?->close();
        });
    }

    protected function waitUntilStopped(RedisPubSubProviderProbe $provider): void
    {
        for ($attempt = 0; $attempt < 1000 && $provider->runningForTest(); ++$attempt) {
            usleep(1000);
        }

        $this->assertFalse($provider->runningForTest(), 'The Redis subscriber lifecycle did not stop.');
    }
}

class RedisPubSubProviderProbe extends RedisPubSubProvider
{
    public bool $useRealRetryWait = false;

    public function subscriberForTest(): ?Subscriber
    {
        return $this->subscriber;
    }

    public function runningForTest(): bool
    {
        return $this->running;
    }

    public function messageHandlerForTest(): PubSubIncomingMessageHandler
    {
        return $this->messageHandler;
    }

    public function waitBeforeRetryForTest(): bool
    {
        return $this->waitBeforeRetry();
    }

    protected function waitBeforeRetry(): bool
    {
        return $this->useRealRetryWait
            ? parent::waitBeforeRetry()
            : false;
    }
}
