<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\Subscriber\Message;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use Redis as PhpRedis;
use RuntimeException;

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
 */
class RedisSubscribeIntegrationTest extends TestCase
{
    use InteractsWithRedis;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('database.redis.default.options.prefix', '');
    }

    public function testSubscribeExitsCleanlyWithNoMessages(): void
    {
        $channelName = 'test_redis_noop_' . uniqid();
        $subscriber = Redis::connection()->subscriber();

        try {
            $subscriber->subscribe($channelName);

            usleep(100_000);

            $subscriber->close();

            $this->assertTrue($subscriber->closed);
        } finally {
            $subscriber->close();
        }
    }

    public function testSubscribeReceivesMessageViaCallback(): void
    {
        $channelName = 'test_redis_sub_' . uniqid();
        $resultChannel = new Channel(1);
        $doneChannel = new Channel(1);

        go(function () use ($channelName, $resultChannel, $doneChannel) {
            try {
                Redis::connection()->subscribe([$channelName], function ($message, $channel) use ($resultChannel) {
                    $resultChannel->push(['message' => $message, 'channel' => $channel]);

                    throw new StopRedisSubscription;
                });
            } catch (StopRedisSubscription) {
                $doneChannel->push(true);
            }
        });

        usleep(100_000);

        $this->publishViaRawClient($channelName, 'hello_world');

        $result = $resultChannel->pop(5.0);
        $this->assertNotFalse($result, 'Subscribe timed out waiting for message');
        $this->assertSame('hello_world', $result['message']);
        $this->assertSame($channelName, $result['channel']);
        $this->assertTrue($doneChannel->pop(5.0));
    }

    public function testPsubscribeReceivesMessageViaCallback(): void
    {
        $pattern = 'test_redis_psub_' . uniqid() . ':*';
        $publishChannel = str_replace('*', 'specific', $pattern);
        $resultChannel = new Channel(1);
        $doneChannel = new Channel(1);

        go(function () use ($pattern, $resultChannel, $doneChannel) {
            try {
                Redis::connection()->psubscribe([$pattern], function ($message, $channel) use ($resultChannel) {
                    $resultChannel->push(['message' => $message, 'channel' => $channel]);

                    throw new StopRedisSubscription;
                });
            } catch (StopRedisSubscription) {
                $doneChannel->push(true);
            }
        });

        usleep(100_000);

        $this->publishViaRawClient($publishChannel, 'pattern_data');

        $result = $resultChannel->pop(5.0);
        $this->assertNotFalse($result, 'Psubscribe timed out waiting for message');
        $this->assertSame('pattern_data', $result['message']);
        $this->assertSame($publishChannel, $result['channel']);
        $this->assertTrue($doneChannel->pop(5.0));
    }

    public function testSubscribeAcceptsStringChannel(): void
    {
        $channelName = 'test_redis_string_' . uniqid();
        $resultChannel = new Channel(1);
        $doneChannel = new Channel(1);

        go(function () use ($channelName, $resultChannel, $doneChannel) {
            try {
                Redis::connection()->subscribe($channelName, function ($message, $channel) use ($resultChannel) {
                    $resultChannel->push(['message' => $message, 'channel' => $channel]);

                    throw new StopRedisSubscription;
                });
            } catch (StopRedisSubscription) {
                $doneChannel->push(true);
            }
        });

        usleep(100_000);

        $this->publishViaRawClient($channelName, 'string_arg');

        $result = $resultChannel->pop(5.0);
        $this->assertNotFalse($result, 'String channel subscribe timed out');
        $this->assertSame('string_arg', $result['message']);
        $this->assertTrue($doneChannel->pop(5.0));
    }

    public function testSubscriberReturnsChannelBasedApi(): void
    {
        $channelName = 'test_redis_subscriber_' . uniqid();
        $subscriber = Redis::connection()->subscriber();

        try {
            $subscriber->subscribe($channelName);

            go(function () use ($channelName) {
                usleep(50_000);
                $this->publishViaRawClient($channelName, 'channel_api');
            });

            $message = $subscriber->channel()->pop(5.0);

            $this->assertInstanceOf(Message::class, $message);
            $this->assertSame($channelName, $message->channel);
            $this->assertSame('channel_api', $message->payload);
        } finally {
            $subscriber->close();
        }
    }

    public function testSubscriberWithPrefix(): void
    {
        $prefix = 'myprefix:';
        $connectionName = $this->createRedisConnectionWithPrefix($prefix);
        $channelName = 'test_redis_prefixed_' . uniqid();
        $subscriber = Redis::connection($connectionName)->subscriber();

        try {
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
        } finally {
            $subscriber->close();
        }
    }

    /**
     * Publish a message using a raw phpredis client (separate connection).
     */
    private function publishViaRawClient(string $channel, string $message): void
    {
        $client = new PhpRedis;
        $client->connect(
            env('REDIS_HOST', '127.0.0.1'),
            (int) env('REDIS_PORT', 6379)
        );

        try {
            $auth = env('REDIS_PASSWORD');
            if ($auth) {
                $client->auth($auth);
            }

            $client->publish($channel, $message);
        } finally {
            $client->close();
        }
    }
}

final class StopRedisSubscription extends RuntimeException
{
}
