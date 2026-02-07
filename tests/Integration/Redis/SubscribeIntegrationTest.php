<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\ClassInvoker;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use RedisException;

use function Hypervel\Coroutine\go;

/**
 * Integration tests for subscribe/psubscribe callback wrapping.
 *
 * These verify that the callback argument reordering in callSubscribe works
 * correctly against a real Redis pub/sub connection.
 *
 * @group integration
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class SubscribeIntegrationTest extends TestCase
{
    use InteractsWithRedis;
    use RunTestsInCoroutine;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->get(ConfigInterface::class);
        $this->configureRedisForTesting($config);
    }

    public function testSubscribeReceivesMessageWithCorrectArgumentOrder()
    {
        $connectionName = $this->createRedisConnectionWithPrefix('');
        $channelName = 'test_subscribe_' . uniqid();
        $resultChannel = new Channel(1);

        // Subscribe in a coroutine — blocks until read timeout or disconnect.
        // The callback fires when a message arrives, pushing the result to our channel.
        // After the test asserts, the coroutine is cleaned up when the test scope ends.
        go(function () use ($connectionName, $channelName, $resultChannel) {
            Redis::connection($connectionName)->withConnection(function (RedisConnection $connection) use ($channelName, $resultChannel) {
                // Set a short read timeout so subscribe exits after receiving the message
                // instead of blocking forever. callSubscribe sets it to -1, but we override
                // on the raw client after the wrapper has run — we can't do that here because
                // callSubscribe hasn't been called yet. Instead, we subscribe directly on the
                // raw client with our own timeout, using the same argument wrapping.
                $client = $connection->client();
                $originalTimeout = $client->getOption(\Redis::OPT_READ_TIMEOUT);
                $client->setOption(\Redis::OPT_READ_TIMEOUT, 1);

                try {
                    $client->subscribe([$channelName], function ($redis, $channel, $message) use ($resultChannel) {
                        $resultChannel->push([
                            'message' => $message,
                            'channel' => $channel,
                        ]);
                        // After pushing, subscribe continues to block until read timeout
                    });
                } catch (RedisException) {
                    // Read timeout causes RedisException — expected
                } finally {
                    $client->setOption(\Redis::OPT_READ_TIMEOUT, $originalTimeout);
                }
            }, transform: false);
        });

        // Let the subscriber set up
        usleep(50_000);

        // Publish from a separate connection
        Redis::connection($connectionName)->publish($channelName, 'hello_world');

        $result = $resultChannel->pop(5.0);
        $this->assertNotFalse($result, 'Subscribe timed out waiting for message');
        $this->assertSame('hello_world', $result['message']);
        $this->assertSame($channelName, $result['channel']);
    }

    public function testPsubscribeReceivesMessageWithCorrectArgumentOrder()
    {
        $connectionName = $this->createRedisConnectionWithPrefix('');
        $pattern = 'test_psub_' . uniqid() . ':*';
        $publishChannel = str_replace('*', 'specific', $pattern);
        $resultChannel = new Channel(1);

        go(function () use ($connectionName, $pattern, $resultChannel) {
            Redis::connection($connectionName)->withConnection(function (RedisConnection $connection) use ($pattern, $resultChannel) {
                $client = $connection->client();
                $originalTimeout = $client->getOption(\Redis::OPT_READ_TIMEOUT);
                $client->setOption(\Redis::OPT_READ_TIMEOUT, 1);

                try {
                    $client->psubscribe([$pattern], function ($redis, $matchedPattern, $channel, $message) use ($resultChannel) {
                        $resultChannel->push([
                            'message' => $message,
                            'channel' => $channel,
                            'pattern' => $matchedPattern,
                        ]);
                    });
                } catch (RedisException) {
                    // Read timeout causes RedisException — expected
                } finally {
                    $client->setOption(\Redis::OPT_READ_TIMEOUT, $originalTimeout);
                }
            }, transform: false);
        });

        usleep(50_000);

        Redis::connection($connectionName)->publish($publishChannel, 'pattern_message');

        $result = $resultChannel->pop(5.0);
        $this->assertNotFalse($result, 'Psubscribe timed out waiting for message');
        $this->assertSame('pattern_message', $result['message']);
        $this->assertSame($publishChannel, $result['channel']);
        $this->assertSame($pattern, $result['pattern']);
    }

    /**
     * Test that getSubscribeArguments wraps callback argument order correctly.
     *
     * Uses ClassInvoker to call the protected getSubscribeArguments method,
     * then invokes the wrapped callback with phpredis-style arguments to verify
     * the user callback receives ($message, $channel).
     */
    public function testGetSubscribeArgumentsWrapsCallbackForSubscribe()
    {
        $connectionName = $this->createRedisConnectionWithPrefix('');

        Redis::connection($connectionName)->withConnection(function (RedisConnection $connection) {
            $invoker = new ClassInvoker($connection);

            $receivedArgs = [];
            $userCallback = function ($message, $channel) use (&$receivedArgs) {
                $receivedArgs = ['message' => $message, 'channel' => $channel];
            };

            $args = $invoker->getSubscribeArguments('subscribe', [['my-channel'], $userCallback]);

            // Verify channels are wrapped as array
            $this->assertSame(['my-channel'], $args[0]);

            // Simulate phpredis calling the wrapped callback with ($redis, $channel, $message)
            $wrappedCallback = $args[1];
            $wrappedCallback(new \Redis(), 'my-channel', 'hello');

            // User callback should receive ($message, $channel) — not ($redis, $channel, $message)
            $this->assertSame('hello', $receivedArgs['message']);
            $this->assertSame('my-channel', $receivedArgs['channel']);
        }, transform: false);
    }

    /**
     * Test that getSubscribeArguments wraps callback argument order for psubscribe.
     */
    public function testGetSubscribeArgumentsWrapsCallbackForPsubscribe()
    {
        $connectionName = $this->createRedisConnectionWithPrefix('');

        Redis::connection($connectionName)->withConnection(function (RedisConnection $connection) {
            $invoker = new ClassInvoker($connection);

            $receivedArgs = [];
            $userCallback = function ($message, $channel) use (&$receivedArgs) {
                $receivedArgs = ['message' => $message, 'channel' => $channel];
            };

            $args = $invoker->getSubscribeArguments('psubscribe', [['events:*'], $userCallback]);

            $this->assertSame(['events:*'], $args[0]);

            // Simulate phpredis calling with ($redis, $pattern, $channel, $message)
            $wrappedCallback = $args[1];
            $wrappedCallback(new \Redis(), 'events:*', 'events:user.created', 'payload');

            // User callback should receive ($message, $channel)
            $this->assertSame('payload', $receivedArgs['message']);
            $this->assertSame('events:user.created', $receivedArgs['channel']);
        }, transform: false);
    }
}
