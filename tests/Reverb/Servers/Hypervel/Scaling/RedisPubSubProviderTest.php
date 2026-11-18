<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Redis\Subscriber\Message;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Reverb\Contracts\Logger;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubIncomingMessageHandler;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisPubSubProvider;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Matching\Argument;
use JsonException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Redis;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Coroutine\go;

class RedisPubSubProviderTest extends ReverbTestCase
{
    protected Logger $logger;

    /**
     * Set up the test logger.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = Double::for(Logger::class)->strict();
        $this->logger->allows('info');
        $this->logger->allows('error');
        $this->app->instance(Logger::class, $this->logger);
        Log::flushState();
    }

    #[RunInSeparateProcess]
    public function testSpawnFailureRollsBackTheLifecycleOwner(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);

        $redis = Double::for(RedisProxy::class)->strict();
        $redis->expects('subscriber')->never();
        $provider = $this->provider($redis);

        try {
            $provider->connect();
            $this->fail('Expected lifecycle coroutine creation to fail.');
        } catch (CoroutineCreateException) {
            $this->assertFalse($provider->runningForTest());
        }
    }

    public function testCallerCancellationDuringStartupReportingLeavesTheLifecycleChildInControl(): void
    {
        $handler = Double::for(ExceptionHandler::class)->strict();
        $this->app->instance(ExceptionHandler::class, $handler);
        $hookFailure = new RuntimeException('The startup hook failed.');
        $reportStarted = new Channel(1);
        $releaseReport = new Channel(1);
        $parentCoroutineId = null;
        $parentCancellation = null;
        $childCoroutineId = null;
        $subscriber = $this->subscriber();
        $redis = Double::for(RedisProxy::class)->strict();
        $provider = $this->provider($redis);

        $handler->expects('report')
            ->with(Argument::same($hookFailure))
            ->resolves(static function () use ($reportStarted, $releaseReport, &$childCoroutineId): void {
                $childCoroutineId = EngineCoroutine::id();
                $reportStarted->push(true);
                $releaseReport->pop();
            });
        Coroutine::afterCreated(static function () use ($hookFailure): void {
            throw $hookFailure;
        });
        $subscriber->expects('subscribe')->with('reverb')->resolves(function () use ($provider): void {
            $provider->disconnect();
        });
        $this->expectSubscriberClose($subscriber);
        $redis->expects('subscriber')->returns($subscriber);

        $canceller = EngineCoroutine::create(static function () use ($reportStarted, &$parentCoroutineId): void {
            $reportStarted->pop();

            if (is_int($parentCoroutineId)) {
                EngineCoroutine::cancelById($parentCoroutineId, throwException: true);
            }
        });

        $parent = EngineCoroutine::create(function () use ($provider, &$parentCoroutineId, &$parentCancellation): void {
            $parentCoroutineId = EngineCoroutine::id();

            try {
                $provider->connect();
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            }
        });

        try {
            $this->assertInstanceOf(CanceledException::class, $parentCancellation);
            $this->assertTrue($provider->runningForTest());
            $this->assertFalse($subscriber->closed);
        } finally {
            $releaseReport->push(true, 0.001);
            $provider->disconnect();

            if (is_int($childCoroutineId)) {
                Coroutine::join([$childCoroutineId], 1);
            }
        }

        $this->assertFalse($provider->runningForTest());
        $this->assertTrue($subscriber->closed);
        $this->assertFalse(Coroutine::exists($parent->getId()));
        $this->assertFalse(Coroutine::exists($canceller->getId()));
    }

    public function testDuplicateConnectCallsRetainOneLifecycleOwner(): void
    {
        $messages = new Channel(1);
        $subscriber = $this->subscriber($messages);
        $subscriber->expects('subscribe')->with('reverb');
        $this->expectSubscriberClose($subscriber, $messages);
        $redis = Double::for(RedisProxy::class)->strict();
        $redis->expects('subscriber')->returns($subscriber);
        $provider = $this->provider($redis);

        $provider->connect();
        $provider->connect();

        $this->assertTrue($provider->runningForTest());

        $provider->disconnect();
        $this->waitUntilStopped($provider);
    }

    public function testConnectionFailuresContinuePastTheOldRetryLimitAndAreLoggedAtABoundedRate(): void
    {
        $redis = Double::for(RedisProxy::class)->strict();
        $provider = $this->provider($redis);
        $attempts = 0;
        $redis->expects('subscriber')->times(61)->resolves(
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
        $this->logger->received('error')
            ->with('Redis connection failed: redis unavailable')
            ->times(2);
    }

    public function testDisconnectDuringSubscriptionDoesNotCommitOrRetry(): void
    {
        $subscriber = $this->subscriber();
        $redis = Double::for(RedisProxy::class)->strict();
        $provider = $this->provider($redis);
        $subscriber->expects('subscribe')->with('reverb')->resolves(
            function () use ($provider): void {
                $provider->disconnect();
            },
        );
        $this->expectSubscriberClose($subscriber);
        $redis->expects('subscriber')->returns($subscriber);

        $provider->connect();
        $this->waitUntilStopped($provider);

        $this->assertNull($provider->subscriberForTest());
        $this->assertTrue($subscriber->closed);
    }

    public function testTransportFailureClosesTheCommittedSubscriberAndRecovers(): void
    {
        $failedMessages = Double::for(Channel::class)->strict();
        $failedMessages->expects('pop')->throws(new RuntimeException('connection lost'));
        $failedSubscriber = $this->subscriber($failedMessages);
        $failedSubscriber->expects('subscribe')->with('reverb');
        $this->expectSubscriberClose($failedSubscriber);

        $recoveredSubscriber = $this->subscriber();
        $redis = Double::for(RedisProxy::class)->strict();
        $provider = $this->provider($redis);
        $recoveredSubscriber->expects('subscribe')->with('reverb')->resolves(
            function () use ($provider): void {
                $provider->disconnect();
            },
        );
        $this->expectSubscriberClose($recoveredSubscriber);
        $redis->expects('subscriber')->times(2)->returns($failedSubscriber, $recoveredSubscriber);

        $provider->connect();
        $this->waitUntilStopped($provider);

        $this->assertTrue($failedSubscriber->closed);
        $this->assertTrue($recoveredSubscriber->closed);
        $this->logger->received('error')
            ->with('Redis connection failed: connection lost')
            ->times(1);
    }

    public function testMessageHandlerFailureDoesNotReplaceTheSubscriber(): void
    {
        $messages = new Channel(1);
        $messages->push(new Message('prefix:reverb', 'payload'));
        $subscriber = $this->subscriber($messages);
        $subscriber->expects('subscribe')->with('reverb');
        $redis = Double::for(RedisProxy::class)->strict();
        $redis->expects('subscriber')->returns($subscriber);
        $provider = $this->provider($redis);
        $this->expectSubscriberClose($subscriber, $messages);
        $handlerFailure = new RuntimeException('handler failed');
        $provider->messageHandlerForTest()
            ->expects('handle')
            ->with('payload')
            ->resolves(function () use ($provider, $handlerFailure): never {
                $provider->disconnect();

                throw $handlerFailure;
            });
        $exceptionHandler = Double::for(ExceptionHandler::class)->strict();
        $exceptionHandler->expects('report')->with(Argument::same($handlerFailure));
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);

        $provider->connect();
        $this->waitUntilStopped($provider);

        $this->logger->received('error')->with('Redis connection failed: handler failed')->never();
    }

    public function testRetryWaitWakesWhenTheWorkerExits(): void
    {
        $provider = $this->provider(Double::for(RedisProxy::class)->strict());
        $provider->useRealRetryWait = true;
        $result = new Channel(1);

        go(fn () => $result->push($provider->waitBeforeRetryForTest()));
        usleep(1000);
        CoordinatorManager::until(Constants::WORKER_EXIT)->resume();

        $this->assertTrue($result->pop(1));
    }

    public function testPublishUsesIndependentRedisConnectionWithoutSubscriber(): void
    {
        [$redis, $client] = $this->publishingConnection();
        $client->expects('publish')->with('reverb', '{"id":1}')->returns(3);
        $provider = $this->provider($redis);

        $this->assertSame(3, $provider->publish(['id' => 1]));
        $this->assertNull($provider->subscriberForTest());
    }

    public function testPublishFailureDoesNotRetainThePayload(): void
    {
        $failure = new RuntimeException('redis unavailable');
        [$redis, $client] = $this->publishingConnection();
        $client->expects('publish')
            ->with('reverb', '{"id":1}')
            ->throws($failure);
        $client->expects('publish')
            ->with('reverb', '{"id":2}')
            ->returns(1);
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
        [$redis, $client] = $this->publishingConnection();
        $client->expects('publish')->never();
        $provider = $this->provider($redis);

        $this->expectException(JsonException::class);

        $provider->publish(['invalid' => NAN]);
    }

    /**
     * Create a publishing connection through the real native forwarding path.
     *
     * @return array{RedisProxy, DoubleInterface&Redis}
     */
    protected function publishingConnection(): array
    {
        $client = Double::for(Redis::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $connection = Double::for((new PhpRedisConnectionStub)->setActiveConnection($client))->passthru();
        $connection->allows('release');
        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('borrow')->returns($connection);
        $manager = Double::for(new PoolManager(new Container))->passthru();
        $manager->allows('pool')->with('reverb')->returns($pool);

        return [new RedisProxy($manager, 'reverb', new RedisSentinelFactory), $client];
    }

    /**
     * Create a subscriber lifecycle probe.
     */
    protected function provider(RedisProxy $redis): RedisPubSubProviderProbe
    {
        return new RedisPubSubProviderProbe(
            Double::for(PubSubIncomingMessageHandler::class)->strict(),
            $redis,
            'reverb',
        );
    }

    /**
     * Create a subscriber with controlled lifecycle state.
     */
    protected function subscriber(?Channel $messages = null): Subscriber
    {
        $subscriber = Double::for(Subscriber::class)->strict();
        $subscriber->prefix = 'prefix:';
        $subscriber->closed = false;

        if ($messages !== null) {
            $subscriber->allows('channel')->returns($messages);
        }

        return $subscriber;
    }

    /**
     * Expect subscriber cleanup and close its message channel.
     */
    protected function expectSubscriberClose(Subscriber $subscriber, ?Channel $messages = null): void
    {
        $subscriber->expects('close')->resolves(function () use ($subscriber, $messages): void {
            $subscriber->closed = true;
            $messages?->close();
        });
    }

    /**
     * Wait for the subscriber lifecycle to finish.
     */
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

    /**
     * Return the committed subscriber.
     */
    public function subscriberForTest(): ?Subscriber
    {
        return $this->subscriber;
    }

    /**
     * Determine whether the lifecycle is running.
     */
    public function runningForTest(): bool
    {
        return $this->running;
    }

    /**
     * Return the incoming message handler.
     */
    public function messageHandlerForTest(): PubSubIncomingMessageHandler
    {
        return $this->messageHandler;
    }

    /**
     * Wait for the next retry.
     */
    public function waitBeforeRetryForTest(): bool
    {
        return $this->waitBeforeRetry();
    }

    /**
     * Skip retry delays unless the test exercises the real wait.
     */
    protected function waitBeforeRetry(): bool
    {
        return $this->useRealRetryWait
            ? parent::waitBeforeRetry()
            : false;
    }
}
