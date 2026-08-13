<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Broadcasting\Redis;

use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\Broadcasters\RedisBroadcaster;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\Subscriber\Message;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

class RedisBroadcasterTest extends TestCase
{
    use InteractsWithRedis;

    private const string REDIS_PREFIX = 'broadcast-test:';

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('database.redis.options.prefix', self::REDIS_PREFIX);
    }

    public function testBroadcastDeliversFormattedChannelsAndPayload(): void
    {
        $suffix = uniqid();
        $channels = ["orders.{$suffix}", "customers.{$suffix}"];
        $formattedChannels = array_map(
            static fn (string $channel): string => 'application.' . $channel,
            $channels,
        );
        $subscriber = Redis::connection()->subscriber();

        Broadcaster::formatChannelsUsing(
            static fn (array $channels): array => array_map(
                static fn (mixed $channel): string => 'application.' . $channel,
                $channels,
            ),
        );

        try {
            $subscriber->subscribe(...$formattedChannels);

            (new RedisBroadcaster(
                $this->app,
                $this->app->make(Factory::class),
                prefix: self::REDIS_PREFIX,
            ))->broadcast(
                $channels,
                'OrderCreated',
                ['order_id' => 42, 'socket' => 'socket-id'],
            );

            $messages = [];

            for ($remainingMessages = count($formattedChannels); $remainingMessages > 0; --$remainingMessages) {
                $message = $subscriber->channel()->pop(5.0);

                $this->assertInstanceOf(Message::class, $message);
                $messages[$message->channel] = json_decode($message->payload, true, flags: JSON_THROW_ON_ERROR);
            }

            foreach ($formattedChannels as $formattedChannel) {
                $physicalChannel = self::REDIS_PREFIX . $formattedChannel;

                $this->assertArrayHasKey($physicalChannel, $messages);
                $payload = $messages[$physicalChannel];

                $this->assertSame('OrderCreated', $payload['event']);
                $this->assertSame(['order_id' => 42], $payload['data']);
                $this->assertSame('socket-id', $payload['socket']);
            }
        } finally {
            $subscriber->close();
        }
    }
}
