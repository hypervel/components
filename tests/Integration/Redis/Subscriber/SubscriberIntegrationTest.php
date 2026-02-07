<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis\Subscriber;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Testbench\TestCase;

use function Hypervel\Coroutine\go;

/**
 * Integration tests for the coroutine-native Redis Subscriber.
 *
 * These tests connect to a real Redis server and verify full pub/sub
 * round-trips using the socket-based subscriber (not phpredis).
 *
 * @group integration
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class SubscriberIntegrationTest extends TestCase
{
    use InteractsWithRedis;
    use RunTestsInCoroutine;

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
    }

    public function testSubscribeReceivesMessage()
    {
        $channelName = 'test_sub_' . uniqid();
        $subscriber = $this->createTestSubscriber();

        $subscriber->subscribe($channelName);

        go(function () use ($channelName) {
            usleep(50_000);
            $this->publishViaRawClient($channelName, 'hello');
        });

        $message = $subscriber->channel()->pop(5.0);

        $this->assertNotFalse($message, 'Timed out waiting for message');
        $this->assertSame($channelName, $message->channel);
        $this->assertSame('hello', $message->payload);
        $this->assertNull($message->pattern);

        $subscriber->close();
    }

    public function testSubscribeToMultipleChannels()
    {
        $channel1 = 'test_multi_a_' . uniqid();
        $channel2 = 'test_multi_b_' . uniqid();
        $subscriber = $this->createTestSubscriber();

        $subscriber->subscribe($channel1, $channel2);

        go(function () use ($channel1, $channel2) {
            usleep(50_000);
            $this->publishViaRawClient($channel1, 'msg1');
            $this->publishViaRawClient($channel2, 'msg2');
        });

        $message1 = $subscriber->channel()->pop(5.0);
        $this->assertNotFalse($message1, 'Timed out waiting for message 1');

        $message2 = $subscriber->channel()->pop(5.0);
        $this->assertNotFalse($message2, 'Timed out waiting for message 2');

        $channels = [$message1->channel, $message2->channel];
        $payloads = [$message1->payload, $message2->payload];

        $this->assertContains($channel1, $channels);
        $this->assertContains($channel2, $channels);
        $this->assertContains('msg1', $payloads);
        $this->assertContains('msg2', $payloads);

        $subscriber->close();
    }

    public function testUnsubscribeStopsReceivingFromChannel()
    {
        $channel1 = 'test_unsub_keep_' . uniqid();
        $channel2 = 'test_unsub_drop_' . uniqid();
        $subscriber = $this->createTestSubscriber();

        $subscriber->subscribe($channel1, $channel2);
        $subscriber->unsubscribe($channel2);

        go(function () use ($channel1, $channel2) {
            usleep(50_000);
            // Publish to unsubscribed channel first — should be ignored
            $this->publishViaRawClient($channel2, 'dropped');
            // Then publish to subscribed channel
            $this->publishViaRawClient($channel1, 'kept');
        });

        $message = $subscriber->channel()->pop(5.0);
        $this->assertNotFalse($message, 'Timed out waiting for message');
        $this->assertSame($channel1, $message->channel);
        $this->assertSame('kept', $message->payload);

        $subscriber->close();
    }

    public function testPsubscribeReceivesMatchingMessage()
    {
        $pattern = 'test_psub_' . uniqid() . ':*';
        $publishChannel = str_replace('*', 'specific', $pattern);
        $subscriber = $this->createTestSubscriber();

        $subscriber->psubscribe($pattern);

        go(function () use ($publishChannel) {
            usleep(50_000);
            $this->publishViaRawClient($publishChannel, 'pattern_data');
        });

        $message = $subscriber->channel()->pop(5.0);

        $this->assertNotFalse($message, 'Timed out waiting for pmessage');
        $this->assertSame($publishChannel, $message->channel);
        $this->assertSame('pattern_data', $message->payload);
        $this->assertSame($pattern, $message->pattern);

        $subscriber->close();
    }

    public function testPunsubscribeStopsReceivingFromPattern()
    {
        $pattern1 = 'test_punsub_keep_' . uniqid() . ':*';
        $pattern2 = 'test_punsub_drop_' . uniqid() . ':*';
        $channel1 = str_replace('*', 'event', $pattern1);
        $channel2 = str_replace('*', 'event', $pattern2);
        $subscriber = $this->createTestSubscriber();

        $subscriber->psubscribe($pattern1, $pattern2);
        $subscriber->punsubscribe($pattern2);

        go(function () use ($channel1, $channel2) {
            usleep(50_000);
            $this->publishViaRawClient($channel2, 'dropped');
            $this->publishViaRawClient($channel1, 'kept');
        });

        $message = $subscriber->channel()->pop(5.0);
        $this->assertNotFalse($message, 'Timed out waiting for pmessage');
        $this->assertSame($channel1, $message->channel);
        $this->assertSame('kept', $message->payload);
        $this->assertSame($pattern1, $message->pattern);

        $subscriber->close();
    }

    public function testSubscribeWithPrefixPrependsToChannels()
    {
        $rawChannel = 'test_prefix_' . uniqid();
        $prefix = 'myapp:';
        $subscriber = $this->createTestSubscriber(prefix: $prefix);

        $subscriber->subscribe($rawChannel);

        go(function () use ($rawChannel, $prefix) {
            usleep(50_000);
            // Publish to the full prefixed channel name
            $this->publishViaRawClient($prefix . $rawChannel, 'prefixed');
        });

        $message = $subscriber->channel()->pop(5.0);

        $this->assertNotFalse($message, 'Timed out waiting for prefixed message');
        $this->assertSame($prefix . $rawChannel, $message->channel);
        $this->assertSame('prefixed', $message->payload);

        $subscriber->close();
    }

    public function testPingReturnsPongWhileSubscribed()
    {
        $channelName = 'test_ping_' . uniqid();
        $subscriber = $this->createTestSubscriber();

        // Must subscribe first — Redis only responds to PING with a multi-bulk
        // pong in subscribe mode. In normal mode it sends +PONG which the
        // RESP parser doesn't handle.
        $subscriber->subscribe($channelName);

        $result = $subscriber->ping(5.0);

        $this->assertSame('pong', $result);

        $subscriber->close();
    }

    public function testCloseStopsReceivingMessages()
    {
        $channelName = 'test_close_' . uniqid();
        $subscriber = $this->createTestSubscriber();

        $subscriber->subscribe($channelName);

        $this->assertFalse($subscriber->closed);

        $subscriber->close();

        $this->assertTrue($subscriber->closed);
        $this->assertFalse($subscriber->channel()->pop(0.1));
    }

    /**
     * Create a Subscriber connected to the test Redis server.
     */
    private function createTestSubscriber(string $prefix = ''): Subscriber
    {
        return new Subscriber(
            host: env('REDIS_HOST', '127.0.0.1'),
            port: (int) env('REDIS_PORT', 6379),
            password: (string) (env('REDIS_AUTH', '') ?: ''),
            timeout: 5.0,
            prefix: $prefix,
        );
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
