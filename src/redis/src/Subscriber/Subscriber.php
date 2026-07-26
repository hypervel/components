<?php

declare(strict_types=1);

namespace Hypervel\Redis\Subscriber;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Channel;
use Hypervel\Redis\Subscriber\Exceptions\SocketException;
use Hypervel\Redis\Subscriber\Exceptions\SubscribeException;
use Throwable;

class Subscriber
{
    public bool $closed = false;

    protected CommandInvoker $commandInvoker;

    /**
     * Active prefixed channel subscriptions.
     *
     * @var array<string, true>
     */
    protected array $channels = [];

    /**
     * Active prefixed pattern subscriptions.
     *
     * @var array<string, true>
     */
    protected array $patterns = [];

    /**
     * Create a new Redis subscriber.
     *
     * @throws SocketException
     * @throws Throwable
     */
    public function __construct(
        public string $host,
        public int $port = 6379,
        public string|array|null $password = null,
        public float $timeout = 5.0,
        public string $prefix = '',
        public ?string $username = null,
        public ?string $scheme = null,
        public array $context = [],
        protected ?StdoutLoggerInterface $logger = null,
    ) {
        $this->connect();
    }

    /**
     * Subscribe to Redis channels.
     *
     * @throws SocketException
     * @throws Throwable
     * @throws SubscribeException
     */
    public function subscribe(string ...$channels): void
    {
        if ($channels === []) {
            throw new SubscribeException('At least one Redis channel is required.');
        }

        $channels = array_map(fn ($channel) => $this->prefix . $channel, $channels);
        $result = $this->commandInvoker->invoke(['subscribe', ...$channels], count($channels));

        foreach ($result as $value) {
            $this->channels[$value[1]] = true;
        }
    }

    /**
     * Unsubscribe from Redis channels.
     *
     * @throws SocketException
     * @throws Throwable
     */
    public function unsubscribe(string ...$channels): void
    {
        $channels = array_map(fn ($channel) => $this->prefix . $channel, $channels);
        $number = $channels === [] ? max(1, count($this->channels)) : count($channels);
        $result = $this->commandInvoker->invoke(['unsubscribe', ...$channels], $number);

        foreach ($result as $value) {
            if ($value[1] === null) {
                $this->channels = [];
            } else {
                unset($this->channels[$value[1]]);
            }
        }
    }

    /**
     * Subscribe to Redis channel patterns.
     *
     * @throws SocketException
     * @throws Throwable
     * @throws SubscribeException
     */
    public function psubscribe(string ...$channels): void
    {
        if ($channels === []) {
            throw new SubscribeException('At least one Redis channel pattern is required.');
        }

        $channels = array_map(fn ($channel) => $this->prefix . $channel, $channels);
        $result = $this->commandInvoker->invoke(['psubscribe', ...$channels], count($channels));

        foreach ($result as $value) {
            $this->patterns[$value[1]] = true;
        }
    }

    /**
     * Unsubscribe from Redis channel patterns.
     *
     * @throws SocketException
     * @throws Throwable
     */
    public function punsubscribe(string ...$channels): void
    {
        $channels = array_map(fn ($channel) => $this->prefix . $channel, $channels);
        $number = $channels === [] ? max(1, count($this->patterns)) : count($channels);
        $result = $this->commandInvoker->invoke(['punsubscribe', ...$channels], $number);

        foreach ($result as $value) {
            if ($value[1] === null) {
                $this->patterns = [];
            } else {
                unset($this->patterns[$value[1]]);
            }
        }
    }

    /**
     * Get the subscriber message channel.
     */
    public function channel(): Channel
    {
        return $this->commandInvoker->channel();
    }

    /**
     * Close the Redis subscriber.
     *
     * @throws SocketException
     */
    public function close(): void
    {
        $this->closed = true;
        $this->commandInvoker->interrupt();
    }

    /**
     * Ping the Redis subscriber connection.
     *
     * @throws SocketException
     * @throws Throwable
     */
    public function ping(float $timeout = 1): string|bool
    {
        return $this->commandInvoker->ping($timeout);
    }

    /**
     * Connect the Redis subscriber.
     *
     * @throws SocketException
     * @throws Throwable
     */
    protected function connect(): void
    {
        $connection = new Connection(
            $this->host,
            $this->port,
            $this->timeout,
            $this->scheme,
            $this->context,
        );
        $this->commandInvoker = new CommandInvoker(
            $connection,
            $this->logger,
            $this->timeout,
        );

        if ($this->password === null || $this->password === '') {
            return;
        }

        $credentials = is_array($this->password)
            ? $this->password
            : (
                $this->username !== null && $this->username !== ''
                    ? [$this->username, $this->password]
                    : [$this->password]
            );

        $this->commandInvoker->invoke(['auth', ...$credentials], 1);
    }
}
