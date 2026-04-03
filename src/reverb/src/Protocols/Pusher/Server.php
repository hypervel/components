<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher;

use Exception;
use Hypervel\Cache\RateLimiter;
use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Events\MessageReceived;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\ConnectionLimitExceeded;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\InvalidOrigin;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\PusherException;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\RateLimitExceeded;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Support\Str;
use Throwable;

class Server
{
    /**
     * Cached rate limiter instance.
     */
    protected ?RateLimiter $rateLimiter = null;

    /**
     * Create a new server instance.
     */
    public function __construct(
        protected ChannelManager $channels,
        protected EventHandler $handler,
    ) {
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
        } catch (Exception $e) {
            if ($connection->hasAcquiredConnectionSlot()) {
                app(SharedState::class)->releaseConnectionSlot($connection->app()->id());
                $connection->clearConnectionSlotAcquired();
            }

            $this->error($connection, $e);
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

            $event = json_decode($message, associative: true, flags: JSON_THROW_ON_ERROR);

            // Try-decode data field instead of validate-then-decode (avoids double parse)
            if (is_string($event['data'] ?? null)) {
                $decoded = json_decode($event['data'], associative: true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $event['data'] = $decoded;
                }
            }

            // Direct type check instead of Validator::make() (hot path optimization)
            if (! isset($event['event']) || ! is_string($event['event'])) {
                throw new Exception('Invalid message format');
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
        $this->channels
            ->for($connection->app())
            ->unsubscribeFromAll($connection);

        if ($connection->hasAcquiredConnectionSlot()) {
            app(SharedState::class)->releaseConnectionSlot($connection->app()->id());
        }

        Log::info('Connection Closed', $connection->id());
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

        $connection->send(json_encode([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]));

        Log::error('Message from ' . $connection->id() . ' resulted in an unknown error');
        Log::info($exception->getMessage());
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
            throw new ConnectionLimitExceeded();
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

        $config = $connection->app()->rateLimiting();

        $this->rateLimiter ??= new RateLimiter(app('cache')->store('array'));

        $key = 'reverb:message:' . $connection->id();

        if ($this->rateLimiter->tooManyAttempts($key, $config['max_attempts'])) {
            if ($config['terminate_on_limit'] ?? false) {
                $connection->terminate();
            }

            throw new RateLimitExceeded();
        }

        $this->rateLimiter->increment($key, $config['decay_seconds'] ?? 1);
    }

    /**
     * Verify the origin of the connection.
     */
    protected function verifyOrigin(Connection $connection): void
    {
        $allowedOrigins = $connection->app()->allowedOrigins();

        if (in_array('*', $allowedOrigins)) {
            return;
        }

        $origin = parse_url($connection->origin(), PHP_URL_HOST);

        foreach ($allowedOrigins as $allowedOrigin) {
            if (Str::is($allowedOrigin, $origin)) {
                return;
            }
        }

        throw new InvalidOrigin();
    }
}
