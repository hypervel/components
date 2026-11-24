<?php

declare(strict_types=1);

namespace Hypervel\Redis\Subscriber;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Redis\Subscriber\Exceptions\SocketException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class CommandInvoker
{
    private const float MESSAGE_PUSH_TIMEOUT = 30.0;

    /** @var Channel<array|string> */
    protected Channel $resultChannel;

    /** @var Channel<Message> */
    protected Channel $messageChannel;

    /** @var Channel<string> */
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
            $result[] = $this->awaitReply($this->resultChannel, $this->timeout, 'command acknowledgement');
        }

        return $result;
    }

    /**
     * Get the subscriber message channel.
     *
     * @return Channel<Message>
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
    public function ping(float $timeout = 1): string
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

        return $this->awaitReply($this->pingChannel, $timeout, 'PONG response');
    }

    /**
     * Wait for a sent command's reply.
     *
     * @template TReply of array|string
     * @param Channel<TReply> $channel
     * @return TReply
     */
    private function awaitReply(Channel $channel, float $timeout, string $reply): array|string
    {
        try {
            $value = $channel->pop($timeout > 0 ? $timeout : -1);

            if ($value !== false) {
                return $value;
            }

            throw $this->receiveFailure ?? match (true) {
                $channel->wasCanceled() => new CanceledException("Waiting for a Redis subscriber {$reply} was canceled."),
                $channel->timedOut() => new SocketException("Timed out waiting for a Redis subscriber {$reply}."),
                default => new SocketException("The Redis subscriber {$reply} channel was closed."),
            };
        } catch (Throwable $exception) {
            // A late reply to this command must never reach the next caller.
            try {
                $this->interrupt();
            } catch (Throwable) {
                // Preserve the original reply failure.
            }

            throw $exception;
        }
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

        if (! $this->messageChannel->timedOut()) {
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
