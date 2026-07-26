<?php

declare(strict_types=1);

namespace Hypervel\Redis\Subscriber;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Redis\Subscriber\Exceptions\SocketException;
use Throwable;

class CommandInvoker
{
    private const float MESSAGE_PUSH_TIMEOUT = 30.0;

    protected Channel $resultChannel;

    protected Channel $messageChannel;

    private Channel $pingChannel;

    private Timer $timer;

    private ?int $shutdownTimerId = null;

    private ?Throwable $receiveFailure = null;

    private bool $interrupted = false;

    /**
     * Create a new Redis subscriber command invoker.
     */
    public function __construct(
        protected Connection $connection,
        protected ?StdoutLoggerInterface $logger = null,
        protected float $timeout = 5.0,
    ) {
        $this->resultChannel = new Channel;
        $this->pingChannel = new Channel;
        $this->messageChannel = new Channel(100);
        $this->timer = new Timer;

        try {
            $this->loop();
            $this->watchForShutdown();
        } catch (Throwable $exception) {
            try {
                $this->interrupt();
            } catch (Throwable) {
                // Construction failure remains primary after exhaustive cleanup.
            }

            throw $exception;
        }
    }

    /**
     * Invoke a Redis subscriber command.
     */
    public function invoke(int|string|array|null $command, int $number): array
    {
        if ($this->interrupted) {
            throw $this->receiveFailure
                ?? new SocketException('The Redis subscriber connection is closed.');
        }

        try {
            $this->connection->send(CommandBuilder::build($command));
        } catch (Throwable $exception) {
            try {
                $this->interrupt();
            } catch (Throwable) {
                // The command-send failure remains primary after cleanup.
            }

            throw $exception;
        }

        $result = [];

        for ($i = 0; $i < $number; ++$i) {
            $value = $this->resultChannel->pop($this->timeout > 0 ? $this->timeout : -1);

            if ($value !== false) {
                $result[] = $value;
                continue;
            }

            if ($this->receiveFailure !== null) {
                throw $this->receiveFailure;
            }

            if ($this->resultChannel->isTimeout()) {
                try {
                    $this->interrupt();
                } catch (Throwable) {
                    // The acknowledgement timeout remains primary after cleanup.
                }

                throw new SocketException(
                    'Timed out waiting for a Redis subscriber command acknowledgement.'
                );
            }

            throw new SocketException(
                'The Redis subscriber command acknowledgement channel was closed.'
            );
        }

        return $result;
    }

    /**
     * Get the subscriber message channel.
     */
    public function channel(): Channel
    {
        return $this->messageChannel;
    }

    /**
     * Interrupt the subscriber connection.
     */
    public function interrupt(): bool
    {
        if ($this->interrupted) {
            return true;
        }

        $this->interrupted = true;

        if ($this->shutdownTimerId !== null) {
            $this->timer->clear($this->shutdownTimerId);
            $this->shutdownTimerId = null;
        }

        try {
            $this->connection->close();
        } finally {
            $this->resultChannel->close();
            $this->pingChannel->close();
            $this->messageChannel->close();
        }

        return true;
    }

    /**
     * Ping the Redis subscriber connection.
     */
    public function ping(float $timeout = 1): string|bool
    {
        if ($this->interrupted) {
            throw $this->receiveFailure
                ?? new SocketException('The Redis subscriber connection is closed.');
        }

        try {
            $this->connection->send(CommandBuilder::build('ping'));
        } catch (Throwable $exception) {
            try {
                $this->interrupt();
            } catch (Throwable) {
                // The PING send failure remains primary after cleanup.
            }

            throw $exception;
        }

        $result = $this->pingChannel->pop($timeout > 0 ? $timeout : -1);

        if ($result !== false) {
            return $result;
        }

        if ($this->receiveFailure !== null) {
            throw $this->receiveFailure;
        }

        if ($this->pingChannel->isTimeout()) {
            try {
                $this->interrupt();
            } catch (Throwable) {
                // The PING timeout remains primary after cleanup.
            }

            throw new SocketException('Timed out waiting for a Redis subscriber PONG response.');
        }

        throw new SocketException('The Redis subscriber PING channel was closed.');
    }

    /**
     * Receive Redis subscriber responses.
     */
    protected function receive(Connection $connection): void
    {
        try {
            while (true) { // @phpstan-ignore while.alwaysTrue (receive or routing failure terminates the loop)
                $this->route($connection->receive());
            }
        } catch (Throwable $exception) {
            if (! $this->interrupted) {
                $this->receiveFailure = $exception;
            }

            try {
                $this->interrupt();
            } catch (Throwable) {
                // The terminal cause remains primary after cleanup.
            }
        }
    }

    /**
     * Route one decoded RESP value.
     */
    private function route(mixed $response): void
    {
        if (is_string($response)) {
            if (strcasecmp($response, 'PONG') === 0) {
                if (! $this->pingChannel->push('pong')) {
                    throw new SocketException('The Redis subscriber PING channel was closed.');
                }

                return;
            }

            if (! $this->resultChannel->push($response)) {
                throw new SocketException(
                    'The Redis subscriber command acknowledgement channel was closed.'
                );
            }

            return;
        }

        if (! is_array($response) || ! isset($response[0]) || ! is_string($response[0])) {
            throw new SocketException('Received a malformed Redis subscriber response.');
        }

        $type = strtolower($response[0]);

        if (in_array($type, ['subscribe', 'unsubscribe', 'psubscribe', 'punsubscribe'], true)) {
            if (count($response) !== 3
                || (
                    ! is_string($response[1])
                    && ! (
                        in_array($type, ['unsubscribe', 'punsubscribe'], true)
                        && $response[1] === null
                    )
                )
                || ! is_int($response[2])) {
                throw new SocketException(
                    "Received a malformed Redis {$type} acknowledgement."
                );
            }

            if (! $this->resultChannel->push($response)) {
                throw new SocketException(
                    'The Redis subscriber command acknowledgement channel was closed.'
                );
            }

            return;
        }

        if ($type === 'message') {
            if (count($response) !== 3
                || ! is_string($response[1])
                || ! is_string($response[2])) {
                throw new SocketException('Received a malformed Redis message.');
            }

            $this->pushMessage(new Message(
                channel: $response[1],
                payload: $response[2],
            ));
            return;
        }

        if ($type === 'pmessage') {
            if (count($response) !== 4
                || ! is_string($response[1])
                || ! is_string($response[2])
                || ! is_string($response[3])) {
                throw new SocketException('Received a malformed Redis pattern message.');
            }

            $this->pushMessage(new Message(
                channel: $response[2],
                payload: $response[3],
                pattern: $response[1],
            ));
            return;
        }

        if ($type === 'pong') {
            if (count($response) !== 2
                || (! is_string($response[1]) && $response[1] !== null)) {
                throw new SocketException('Received a malformed Redis PONG response.');
            }

            if (! $this->pingChannel->push('pong')) {
                throw new SocketException('The Redis subscriber PING channel was closed.');
            }

            return;
        }

        throw new SocketException(
            "Received an unsupported Redis subscriber response [{$response[0]}]."
        );
    }

    /**
     * Push a message into the bounded consumer channel.
     */
    private function pushMessage(Message $message): void
    {
        if ($this->messageChannel->push($message, self::MESSAGE_PUSH_TIMEOUT)) {
            return;
        }

        if (! $this->messageChannel->isTimeout()) {
            throw new SocketException('The Redis subscriber message channel was closed.');
        }

        $exception = new SocketException(sprintf(
            'Redis subscriber message channel [%s] remained full for %s seconds.',
            $message->channel,
            self::MESSAGE_PUSH_TIMEOUT,
        ));

        try {
            $this->logger?->error(sprintf(
                'Message channel (%s) is %s seconds full, disconnected',
                $message->channel,
                self::MESSAGE_PUSH_TIMEOUT,
            ));
        } catch (Throwable) {
            // Reporting must not replace the channel-capacity failure.
        }

        throw $exception;
    }

    /**
     * Watch for worker shutdown and interrupt the connection.
     *
     * Without this, the receive loop's socket recv blocks indefinitely
     * and Swoole's coroutine scheduler cannot detect the deadlock (active
     * I/O keeps the event loop alive). This provides a deterministic
     * shutdown path that doesn't depend on coroutine scheduling order.
     */
    protected function watchForShutdown(): void
    {
        $this->shutdownTimerId = $this->timer->until(function (): void {
            $this->interrupt();
        });
    }

    /**
     * Start the Redis subscriber receive loop.
     */
    protected function loop(): void
    {
        Coroutine::create(function (): void {
            $this->receive($this->connection);
        });
    }
}
