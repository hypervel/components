<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Reverb\FailureReporter;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubIncomingMessageHandler;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
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
     * Whether the subscriber lifecycle coroutine is running.
     */
    protected bool $running = false;

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
     * Start the Redis subscriber lifecycle.
     *
     * Uses the injected Redis connection's subscriber() factory so the
     * dedicated subscriber inherits its topology, credentials, and prefix.
     */
    public function connect(): void
    {
        if ($this->running) {
            return;
        }

        $this->shouldRetry = true;
        $this->running = true;

        try {
            go(fn () => $this->runSubscriberLifecycle());
        } catch (Throwable $exception) {
            $this->running = false;

            throw $exception;
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
     * Own subscriber construction, consumption, cleanup, and retry.
     */
    protected function runSubscriberLifecycle(): void
    {
        $workerExit = CoordinatorManager::until(Constants::WORKER_EXIT);

        try {
            while ($this->shouldRetry() && ! $workerExit->isClosing()) {
                $subscriber = null;

                try {
                    $subscriber = $this->redis->subscriber();
                    $subscribedChannel = $subscriber->prefix . $this->channel;
                    $subscriber->subscribe($this->channel);

                    if (! $this->shouldRetry()) {
                        continue;
                    }

                    $this->subscriber = $subscriber;
                    $this->subscribedChannel = $subscribedChannel;
                    $this->retryTimer = 0;
                    Log::info('Redis connection established');
                    $this->consumeMessages($subscriber, $subscribedChannel);
                } catch (Throwable $exception) {
                    $this->reportConnectionFailure($exception);
                } finally {
                    $this->clearSubscriber($subscriber);
                    $this->closeSubscriber($subscriber);
                }

                if ($this->shouldRetry() && $this->waitBeforeRetry()) {
                    break;
                }
            }
        } finally {
            $this->running = false;
        }
    }

    /**
     * Process messages from one committed subscriber.
     */
    protected function consumeMessages(Subscriber $subscriber, string $subscribedChannel): void
    {
        $channel = $subscriber->channel();

        while (true) {
            $message = $channel->pop();

            if ($message === false) {
                break;
            }

            if ($message->channel !== $subscribedChannel) {
                continue;
            }

            try {
                $this->messageHandler->handle($message->payload);
            } catch (Throwable $exception) {
                FailureReporter::report($exception);
            }
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
     * Report a connection failure without flooding logs during an outage.
     */
    protected function reportConnectionFailure(Throwable $exception): void
    {
        ++$this->retryTimer;

        if ($this->retryTimer === 1 || $this->retryTimer % 60 === 0) {
            Log::error('Redis connection failed: ' . $exception->getMessage());
        }
    }

    /**
     * Wait before attempting another connection.
     */
    protected function waitBeforeRetry(): bool
    {
        return CoordinatorManager::until(Constants::WORKER_EXIT)->yield(1);
    }

    /**
     * Determine whether reconnect work remains enabled.
     *
     * Hooked Redis I/O and coordinator waits may yield while disconnect()
     * changes this state.
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
