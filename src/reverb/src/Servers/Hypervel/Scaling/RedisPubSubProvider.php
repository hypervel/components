<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubIncomingMessageHandler;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Support\Sleep;
use Throwable;

use function Hypervel\Coroutine\go;

class RedisPubSubProvider implements PubSubProvider
{
    /**
     * The Redis subscriber instance.
     */
    protected ?Subscriber $subscriber = null;

    /**
     * The actual Redis channel name (with prefix applied).
     *
     * The Subscriber prepends its prefix when subscribing, so incoming
     * messages use the prefixed name. This is computed once during connect().
     */
    protected string $subscribedChannel = '';

    /**
     * Whether the provider should attempt to reconnect.
     */
    protected bool $shouldRetry = true;

    /**
     * The number of seconds elapsed since attempting to reconnect.
     */
    protected int $retryTimer = 0;

    /**
     * Publishes queued while disconnected.
     *
     * @var list<array>
     */
    protected array $queuedPublishes = [];

    /**
     * Create a new Redis pub/sub provider instance.
     */
    public function __construct(
        protected PubSubIncomingMessageHandler $messageHandler,
        protected RedisProxy $redis,
        protected string $channel,
    ) {
    }

    /**
     * Connect to Redis and start subscribing.
     *
     * Uses the injected Redis connection's subscriber() factory to create
     * a Subscriber with the same host, port, password, and prefix as the
     * connection used for publishing.
     */
    public function connect(): void
    {
        $this->shouldRetry = true;

        try {
            $this->subscriber = $this->redis->subscriber();
            $this->subscribedChannel = $this->subscriber->prefix . $this->channel;

            $this->retryTimer = 0;
            $this->processQueuedPublishes();

            Log::info('Redis connection established');

            go(fn () => $this->subscribe());
        } catch (Throwable $e) {
            Log::error('Redis connection failed: ' . $e->getMessage());
            $this->reconnect();
        }
    }

    /**
     * Disconnect from Redis.
     */
    public function disconnect(): void
    {
        $this->shouldRetry = false;
        $this->subscriber?->close();
        $this->subscriber = null;
    }

    /**
     * Subscribe to the Redis channel and process messages.
     */
    public function subscribe(): void
    {
        try {
            $this->subscriber->subscribe($this->channel);

            $channel = $this->subscriber->channel();

            while (true) {
                $message = $channel->pop();

                if ($message === false) {
                    break;
                }

                if ($message->channel === $this->subscribedChannel) {
                    try {
                        $this->messageHandler->handle($message->payload);
                    } catch (Throwable $e) {
                        Log::error('Failed to handle pub/sub message: ' . $e->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            // Connection-level errors (socket failure, subscribe failure) —
            // these require reconnection.
            Log::error('Redis subscriber error: ' . $e->getMessage());
        }

        $this->subscriber = null;
        $this->reconnect();
    }

    /**
     * Listen for a given event type.
     */
    public function on(string $event, callable $callback): void
    {
        $this->messageHandler->listen($event, $callback);
    }

    /**
     * Listen for the given event.
     */
    public function listen(string $event, callable $callback): void
    {
        $this->on($event, $callback);
    }

    /**
     * Stop listening for the given event.
     */
    public function stopListening(string $event): void
    {
        $this->messageHandler->stopListening($event);
    }

    /**
     * Publish a payload to the Redis channel.
     */
    public function publish(array $payload): int
    {
        if ($this->subscriber === null) {
            $this->queuedPublishes[] = $payload;

            return 0;
        }

        return (int) $this->redis->publish($this->channel, json_encode($payload));
    }

    /**
     * Attempt to reconnect to Redis.
     */
    protected function reconnect(): void
    {
        if (! $this->shouldRetry) {
            return;
        }

        $timeout = 60;
        ++$this->retryTimer;

        if ($this->retryTimer >= $timeout) {
            Log::error("Failed to connect to Redis after retrying for {$timeout}s.");

            return;
        }

        Log::info('Attempting Redis reconnection');

        Sleep::sleep(1);

        $this->connect();
    }

    /**
     * Process any publishes that were queued during disconnection.
     */
    protected function processQueuedPublishes(): void
    {
        $queued = $this->queuedPublishes;
        $this->queuedPublishes = [];

        foreach ($queued as $payload) {
            $this->publish($payload);
        }
    }
}
