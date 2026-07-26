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
     * Uses the injected Redis connection's subscriber() factory so the
     * dedicated subscriber inherits its topology, credentials, and prefix.
     */
    public function connect(): void
    {
        $this->shouldRetry = true;
        $subscriber = null;

        try {
            $subscriber = $this->redis->subscriber();
            $subscribedChannel = $subscriber->prefix . $this->channel;
            $subscriber->subscribe($this->channel);

            if (! $this->shouldRetry()) {
                $this->closeSubscriber($subscriber);

                return;
            }

            $this->subscriber = $subscriber;
            $this->subscribedChannel = $subscribedChannel;

            if (! $this->shouldRetry() || $this->subscriber !== $subscriber) {
                $this->clearSubscriber($subscriber);
                $this->closeSubscriber($subscriber);

                return;
            }

            go(fn () => $this->consumeMessages($subscriber, $subscribedChannel));

            if ($this->subscriber === $subscriber) {
                $this->retryTimer = 0;
                Log::info('Redis connection established');
            }
        } catch (Throwable $e) {
            $this->clearSubscriber($subscriber);
            $this->closeSubscriber($subscriber);
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
        $subscriber = $this->subscriber;
        $this->clearSubscriber($subscriber);
        $this->closeSubscriber($subscriber);
    }

    /**
     * Process messages from one committed subscriber.
     */
    protected function consumeMessages(Subscriber $subscriber, string $subscribedChannel): void
    {
        $shouldReconnect = false;

        try {
            $channel = $subscriber->channel();

            while (true) {
                $message = $channel->pop();

                if ($message === false) {
                    break;
                }

                if ($message->channel === $subscribedChannel) {
                    try {
                        $this->messageHandler->handle($message->payload);
                    } catch (Throwable $e) {
                        Log::error('Failed to handle pub/sub message: ' . $e->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            // Connection-level errors require reconnection.
            Log::error('Redis subscriber error: ' . $e->getMessage());
        } finally {
            $shouldReconnect = $this->subscriber === $subscriber;
            $this->clearSubscriber($subscriber);
            $this->closeSubscriber($subscriber);
        }

        if ($shouldReconnect) {
            $this->reconnect();
        }
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
        return (int) $this->redis->publish($this->channel, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Attempt to reconnect to Redis.
     */
    protected function reconnect(): void
    {
        if (! $this->shouldRetry()) {
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

        if (! $this->shouldRetry()) {
            return;
        }

        $this->connect();
    }

    /**
     * Determine whether reconnect work remains enabled.
     *
     * Hooked Redis I/O and Sleep may yield while disconnect() changes this state.
     *
     * @phpstan-impure
     */
    protected function shouldRetry(): bool
    {
        return $this->shouldRetry;
    }

    /**
     * Clear committed state only when it still belongs to the given subscriber.
     */
    protected function clearSubscriber(?Subscriber $subscriber): void
    {
        if ($subscriber === null || $this->subscriber !== $subscriber) {
            return;
        }

        $this->subscriber = null;
        $this->subscribedChannel = '';
    }

    /**
     * Close an owned subscriber without replacing the primary lifecycle failure.
     */
    protected function closeSubscriber(?Subscriber $subscriber): void
    {
        if ($subscriber === null || $subscriber->closed) {
            return;
        }

        try {
            $subscriber->close();
        } catch (Throwable $exception) {
            try {
                Log::error('Unable to close Redis subscriber: ' . $exception->getMessage());
            } catch (Throwable) {
                // Cleanup reporting must not replace the lifecycle failure.
            }
        }
    }
}
