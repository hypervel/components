<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\Subscriber\Message;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

use function Hypervel\Coroutine\go;

/**
 * Integration tests for Redis subscribe/psubscribe and subscriber() wiring.
 *
 * These verify that Redis::subscribe(), Redis::psubscribe(), and
 * Redis::subscriber() correctly use the coroutine-native socket subscriber
 * (not phpredis) against a real Redis server.
 *
 * All tests use a no-prefix connection to avoid prefix mismatch between
 * the socket subscriber and the raw phpredis publish client.
 *
 * @group integration
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class RedisSubscribeIntegrationTest extends TestCase
{
    use InteractsWithRedis;
    use RunTestsInCoroutine;

    protected string $connectionName;

    protected function setUp(): void
    {
        parent::setUp();

        // RunTestsInCoroutine resumes the WORKER_EXIT coordinator after each test,
        // closing its channel. Clear it so each test gets a fresh coordinator.
        CoordinatorManager::clear(Constants::WORKER_EXIT);
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->get(ConfigInterface::class);
        $this->configureRedisForTesting($config);
        $this->connectionName = $this->createRedisConnectionWithPrefix('');
    }

    public function testSubscribeExitsCleanlyWithNoMessages()
    {
        $channelName = 'test_redis_noop_' . uniqid();
        $subscribed = new Channel(1);

        go(function () use ($channelName, $subscribed) {
            Redis::connection($this->connectionName)->subscribe([$channelName], function () use ($subscribed) {
                $subscribed->push(true);
            });
        });

        // Wait for the subscriber to be established, then let the test end.
        // The WORKER_EXIT shutdown watcher should cleanly interrupt the
        // subscriber — without it, the orphaned socket recv coroutine
        // would block Swoole's run() indefinitely.
        usleep(100_000);

        // No message published — subscriber received nothing.
        // If we reach this assertion, the test didn't hang.
        $this->assertTrue(true);
    }

    public function testSubscribeReceivesMessageViaCallback()
    {
        $channelName = 'test_redis_sub_' . uniqid();
        $resultChannel = new Channel(1);

        go(function () use ($channelName, $resultChannel) {
            Redis::connection($this->connectionName)->subscribe([$channelName], function ($message, $channel) use ($resultChannel) {
                $resultChannel->push(['message' => $message, 'channel' => $channel]);
            });
        });

        usleep(100_000);

        $this->publishViaRawClient($channelName, 'hello_world');

        $result = $resultChannel->pop(5.0);
        $this->assertNotFalse($result, 'Subscribe timed out waiting for message');
        $this->assertSame('hello_world', $result['message']);
        $this->assertSame($channelName, $result['channel']);
    }

    public function testPsubscribeReceivesMessageViaCallback()
    {
        $pattern = 'test_redis_psub_' . uniqid() . ':*';
        $publishChannel = str_replace('*', 'specific', $pattern);
        $resultChannel = new Channel(1);

        go(function () use ($pattern, $resultChannel) {
            Redis::connection($this->connectionName)->psubscribe([$pattern], function ($message, $channel) use ($resultChannel) {
                $resultChannel->push(['message' => $message, 'channel' => $channel]);
            });
        });

        usleep(100_000);

        $this->publishViaRawClient($publishChannel, 'pattern_data');

        $result = $resultChannel->pop(5.0);
        $this->assertNotFalse($result, 'Psubscribe timed out waiting for message');
        $this->assertSame('pattern_data', $result['message']);
        $this->assertSame($publishChannel, $result['channel']);
    }

    public function testSubscribeAcceptsStringChannel()
    {
        $channelName = 'test_redis_string_' . uniqid();
        $resultChannel = new Channel(1);

        go(function () use ($channelName, $resultChannel) {
            Redis::connection($this->connectionName)->subscribe($channelName, function ($message, $channel) use ($resultChannel) {
                $resultChannel->push(['message' => $message, 'channel' => $channel]);
            });
        });

        usleep(100_000);

        $this->publishViaRawClient($channelName, 'string_arg');

        $result = $resultChannel->pop(5.0);
        $this->assertNotFalse($result, 'String channel subscribe timed out');
        $this->assertSame('string_arg', $result['message']);
    }

    public function testSubscriberReturnsChannelBasedApi()
    {
        $channelName = 'test_redis_subscriber_' . uniqid();
        $subscriber = Redis::connection($this->connectionName)->subscriber();

        $subscriber->subscribe($channelName);

        go(function () use ($channelName) {
            usleep(50_000);
            $this->publishViaRawClient($channelName, 'channel_api');
        });

        $message = $subscriber->channel()->pop(5.0);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame($channelName, $message->channel);
        $this->assertSame('channel_api', $message->payload);

        $subscriber->close();
    }

    public function testSubscriberWithPrefix()
    {
        $prefix = 'myprefix:';
        $connectionName = $this->createRedisConnectionWithPrefix($prefix);
        $channelName = 'test_redis_prefixed_' . uniqid();
        $subscriber = Redis::connection($connectionName)->subscriber();

        $subscriber->subscribe($channelName);

        go(function () use ($channelName, $prefix) {
            usleep(50_000);
            // Publish to the full prefixed channel name
            $this->publishViaRawClient($prefix . $channelName, 'prefixed_data');
        });

        $message = $subscriber->channel()->pop(5.0);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame($prefix . $channelName, $message->channel);
        $this->assertSame('prefixed_data', $message->payload);

        $subscriber->close();
    }

    /**
     * Publish a message using a raw phpredis client (separate connection).
     */
    private function publishViaRawClient(string $channel, string $message): void
    {
        $client = new \Redis();
        $client->connect(
            env('REDIS_HOST', '127.0.0.1'),
            (int) env('REDIS_PORT', 6379)
        );

        $auth = env('REDIS_AUTH');
        if ($auth) {
            $client->auth($auth);
        }

        $client->publish($channel, $message);
        $client->close();
    }
}
