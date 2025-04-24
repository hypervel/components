<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Closure;
use FriendsOfHyperf\Redis\Subscriber\Exception\SocketException;
use FriendsOfHyperf\Redis\Subscriber\Subscriber as RedisSubscriber;
use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\Context\ApplicationContext;
use Hypervel\Support\Arr;

class Subscriber
{
    public function __construct(
        protected array $config,
        protected ?RedisSubscriber $subscriber = null
    ) {
        $this->subscriber = $subscriber ?: $this->createSubscriber($config);
    }

    protected function createSubscriber(array $config): RedisSubscriber
    {
        return new RedisSubscriber(
            $config['host'] ?? 'localhost',
            $config['port'] ?? 6379,
            $config['auth'] ?? '',
            $config['timeout'] ?? 5,
            $config['options']['prefix'] ?? '',
            ApplicationContext::getContainer()->get(StdoutLoggerInterface::class),
        );
    }

    /**
     * Subscribe to a set of given channels for messages.
     */
    public function subscribe(array|string $channels, Closure $callback): void
    {
        $this->subscriber->subscribe(...Arr::wrap($channels));

        while ($data = $this->subscriber->channel()->pop()) {
            $callback($data->payload, $data->channel);
        }

        if (! $this->subscriber->closed) {
            throw new SocketException('Redis connection is disconnected abnormally.');
        }
    }

    /**
     * Subscribe to a set of given channels with wildcards.
     */
    public function psubscribe(array|string $channels, Closure $callback): void
    {
        $this->subscriber->psubscribe(...Arr::wrap($channels));

        while ($data = $this->subscriber->channel()->pop()) {
            $callback($data->payload, $data->channel);
        }

        if (! $this->subscriber->closed) {
            throw new SocketException('Redis connection is disconnected abnormally.');
        }
    }

    public function __destruct()
    {
        $this->subscriber->close();
    }
}
