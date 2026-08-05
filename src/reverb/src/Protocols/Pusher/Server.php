<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher;

use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Events\ConnectionClosed;
use Hypervel\Reverb\Events\ConnectionEstablished;
use Hypervel\Reverb\Events\MessageReceived;
use Hypervel\Reverb\FailureReporter;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\ConnectionLimitExceeded;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\InvalidMessageFormat;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\InvalidOrigin;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\PusherException;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\RateLimitExceeded;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Support\Str;
use JsonException;
use Throwable;

class Server
{
    /**
     * The per-connection message limiter.
     */
    protected Limiter $messageRateLimiter;

    /**
     * Create a new server instance.
     */
    public function __construct(
        protected ChannelManager $channels,
        protected EventHandler $handler,
        RateLimiter $rateLimiter,
    ) {
        $this->messageRateLimiter = $rateLimiter->store('worker-array');
    }

    /**
     * Handle a client connection.
     */
    public function open(Connection $connection): void
    {
        try {
            $this->ensureWithinConnectionLimit($connection);
            $this->verifyOrigin($connection);

            $connection->touch();

            $this->handler->handle($connection, 'pusher:connection_established');

            Log::info('Connection Established', $connection->id());

            if (app('events')->hasListeners(ConnectionEstablished::class)) {
                ConnectionEstablished::dispatch($connection);
            }

            $connection->markEstablished();
        } catch (Throwable $e) {
            $cleanupFailure = null;

            if ($connection->hasAcquiredConnectionSlot()) {
                try {
                    app(SharedState::class)->releaseConnectionSlot($connection->app()->id());
                    $connection->clearConnectionSlotAcquired();
                } catch (Throwable $throwable) {
                    $cleanupFailure = $throwable;
                }
            }

            try {
                $this->error($connection, $e);
            } catch (Throwable $throwable) {
                $cleanupFailure ??= $throwable;
            }

            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        }
    }

    /**
     * Handle a new message received by the connected client.
     */
    public function message(Connection $from, string $message): void
    {
        Log::info('Message Received', $from->id());
        Log::message($message);

        $from->touch();

        try {
            $this->ensureWithinRateLimit($from);

            try {
                $event = json_decode($message, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidMessageFormat($exception->getMessage(), previous: $exception);
            }

            // Try-decode data field instead of validate-then-decode (avoids double parse)
            if (is_string($event['data'] ?? null)) {
                try {
                    $event['data'] = json_decode(
                        $event['data'],
                        associative: true,
                        flags: JSON_THROW_ON_ERROR,
                    );
                } catch (JsonException $exception) {
                    throw new InvalidMessageFormat($exception->getMessage(), previous: $exception);
                }
            }

            // Direct type check instead of Validator::make() (hot path optimization)
            if (! isset($event['event']) || ! is_string($event['event'])) {
                throw new InvalidMessageFormat;
            }

            if (Str::startsWith($event['event'], 'pusher:')
                && ! empty($event['data'])
                && ! is_array($event['data'])) {
                throw new InvalidMessageFormat('Invalid Pusher event data');
            }

            match (Str::startsWith($event['event'], 'pusher:')) {
                true => $this->handler->handle(
                    $from,
                    $event['event'],
                    empty($event['data']) ? [] : $event['data'],
                ),
                default => ClientEvent::handle($from, $event)
            };

            Log::info('Message Handled', $from->id());

            if (app('events')->hasListeners(MessageReceived::class)) {
                MessageReceived::dispatch($from, $message);
            }
        } catch (Throwable $e) {
            $this->error($from, $e);
        }
    }

    /**
     * Handle a low-level WebSocket control frame.
     */
    public function control(Connection $from, int $opcode): void
    {
        Log::info('Control Frame Received', $from->id());

        $from->setUsesControlFrames();

        if (in_array($opcode, [WEBSOCKET_OPCODE_PING, WEBSOCKET_OPCODE_PONG], true)) {
            $from->touch();
        }
    }

    /**
     * Handle a client disconnection.
     *
     * Called from WebSocketHandler::onClose() when the client has already
     * disconnected. Only cleans up Reverb state — does NOT try to close
     * the connection again (the fd is already gone).
     */
    public function close(Connection $connection): void
    {
        $connection->markDisconnecting();
        $exception = null;

        if ($connection->isEstablished()) {
            try {
                $this->channels
                    ->for($connection->app())
                    ->unsubscribeFromAll($connection);
            } catch (Throwable $throwable) {
                $exception = $throwable;
            }
        }

        if ($connection->hasAcquiredConnectionSlot()) {
            try {
                app(SharedState::class)->releaseConnectionSlot($connection->app()->id());
                $connection->clearConnectionSlotAcquired();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($connection->hasInitializedRateLimiter()) {
            try {
                $this->messageRateLimiter->clear($this->messageLimit($connection));
                $connection->clearRateLimiterInitialized();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($connection->isEstablished()) {
            try {
                Log::info('Connection Closed', $connection->id());

                if (app('events')->hasListeners(ConnectionClosed::class)) {
                    ConnectionClosed::dispatch($connection);
                }
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Handle an error.
     */
    public function error(Connection $connection, Throwable $exception): void
    {
        if ($exception instanceof PusherException) {
            $connection->send(json_encode($exception->payload()));

            Log::error('Message from ' . $connection->id() . ' resulted in a pusher error');
            Log::info($exception->getMessage());

            return;
        }

        $connection->send(json_encode((new InvalidMessageFormat)->payload()));

        Log::error('Message from ' . $connection->id() . ' resulted in an unknown error');
        Log::info($exception->getMessage());

        FailureReporter::report($exception);
    }

    /**
     * Ensure the server is within the connection limit.
     *
     * Uses SharedState for global connection counting across workers.
     */
    protected function ensureWithinConnectionLimit(Connection $connection): void
    {
        if (! $connection->app()->hasMaxConnectionLimit()) {
            return;
        }

        $allowed = app(SharedState::class)->acquireConnectionSlot(
            $connection->app()->id(),
            $connection->app()->maxConnections(),
        );

        if (! $allowed) {
            throw new ConnectionLimitExceeded;
        }

        $connection->markConnectionSlotAcquired();
    }

    /**
     * Ensure the connection is within the message rate limit.
     */
    protected function ensureWithinRateLimit(Connection $connection): void
    {
        if (! $connection->app()->usesRateLimiting()) {
            return;
        }

        if ($this->messageRateLimiter->consume($this->messageLimit($connection))->denied()) {
            $config = $connection->app()->rateLimiting();

            if ($config['terminate_on_limit'] ?? false) {
                $connection->terminate();
            }

            throw new RateLimitExceeded;
        }

        $connection->markRateLimiterInitialized();
    }

    /**
     * Build the message limit for a connection.
     */
    protected function messageLimit(Connection $connection): Limit
    {
        $config = $connection->app()->rateLimiting();

        return Limit::perSecond($config['max_attempts'], $config['decay_seconds'] ?? 1)
            ->by('reverb:message:' . $connection->id());
    }

    /**
     * Verify the origin of the connection.
     */
    protected function verifyOrigin(Connection $connection): void
    {
        $allowedOrigins = $connection->app()->allowedOrigins();

        if (in_array('*', $allowedOrigins, true)) {
            return;
        }

        $origin = $connection->origin();

        if (! is_string($origin)) {
            throw new InvalidOrigin;
        }

        $origin = parse_url($origin, PHP_URL_HOST);

        foreach ($allowedOrigins as $allowedOrigin) {
            if (Str::is($allowedOrigin, $origin)) {
                return;
            }
        }

        throw new InvalidOrigin;
    }
}
