<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\Socket\SocketOptionInterface;
use Hypervel\Contracts\Engine\SocketInterface;
use Hypervel\Engine\Exceptions\SocketClosedException;
use Hypervel\Engine\Exceptions\SocketTimeoutException;
use Hypervel\Support\FailureReporter;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Socket;
use Throwable;

class SafeSocket implements SocketInterface
{
    protected Channel $channel;

    protected bool $loop = false;

    protected ?SocketOptionInterface $option = null;

    /**
     * Create a new safe socket instance.
     */
    public function __construct(
        protected Socket $socket,
        int $capacity = 65535,
        protected bool $throw = true,
        protected ?LoggerInterface $logger = null
    ) {
        $this->channel = new Channel($capacity);
    }

    /**
     * Set the socket option.
     */
    public function setSocketOption(SocketOptionInterface $option): void
    {
        $this->option = $option;
    }

    /**
     * Get the socket option.
     */
    public function getSocketOption(): ?SocketOptionInterface
    {
        return $this->option;
    }

    /**
     * Send all data to the socket.
     *
     * @throws CanceledException when the caller is canceled while waiting
     * @throws SocketTimeoutException when the operation times out
     * @throws SocketClosedException when the client is closed
     */
    public function sendAll(string $data, float $timeout = 0): false|int
    {
        $this->loop();

        if (! $this->channel->push([$data, $timeout], $timeout)) {
            if ($this->channel->wasCanceled()) {
                throw new CanceledException('Sending to the socket was canceled.');
            }

            if ($this->throw && $this->channel->timedOut()) {
                throw new SocketTimeoutException('The channel is full.');
            }

            if ($this->throw && $this->channel->isClosed()) {
                throw new SocketClosedException('The channel is closed.');
            }

            return false;
        }

        return strlen($data);
    }

    /**
     * Receive all data from the socket.
     *
     * @throws CanceledException when the caller is canceled while waiting
     * @throws SocketTimeoutException when the operation times out
     * @throws SocketClosedException when the client is closed
     */
    public function recvAll(int $length = 65536, float $timeout = 0): false|string
    {
        $result = $this->socket->recvAll($length, $timeout);

        return $this->receiveResult($result, $result === '' ? 0 : $this->socket->errCode);
    }

    /**
     * Receive a packet from the socket.
     *
     * @throws CanceledException when the caller is canceled while waiting
     * @throws SocketTimeoutException when the operation times out
     * @throws SocketClosedException when the client is closed
     */
    public function recvPacket(float $timeout = 0): false|string
    {
        $result = $this->socket->recvPacket($timeout);

        return $this->receiveResult($result, $this->socket->errCode);
    }

    /**
     * Classify a native read, including a read canceled after some bytes arrived.
     */
    private function receiveResult(false|string $result, int $errorCode): false|string
    {
        if ($errorCode === SOCKET_ECANCELED && Coroutine::isCanceled()) {
            throw new CanceledException('Receiving from the socket was canceled.');
        }

        if (! $this->throw || ($result !== false && $result !== '')) {
            return $result;
        }

        if ($result === false && $errorCode === SOCKET_ETIMEDOUT) {
            throw new SocketTimeoutException($this->socket->errMsg ?: 'Recv timeout', $errorCode);
        }

        // An empty packet at EOF can retain a previous timeout.
        if ($errorCode === 0 || ($result === '' && $errorCode === SOCKET_ETIMEDOUT)) {
            throw new SocketClosedException('The socket is closed.');
        }

        throw new SocketClosedException(
            $this->socket->errMsg ?: 'The socket is closed.',
            $errorCode,
        );
    }

    /**
     * Close the socket.
     */
    public function close(): bool
    {
        $this->channel->close();

        return $this->socket->close();
    }

    /**
     * Set the logger.
     */
    public function setLogger(?LoggerInterface $logger): static
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Start the send loop.
     */
    protected function loop(): void
    {
        if ($this->loop || $this->channel->isClosed()) {
            return;
        }

        $this->loop = true;
        $started = false;

        try {
            // @phpstan-ignore hypervel.rawCoroutine (the engine package sits below hypervel/coroutine, and the send loop contains every failure, including its logger's)
            Coroutine::create(function () use (&$started): void {
                $started = true;

                try {
                    while (true) {
                        $data = $this->channel->pop(-1);

                        if ($this->channel->isClosed()) {
                            return;
                        }

                        [$data, $timeout] = $data;

                        if ($this->socket->sendAll($data, $timeout) === false) {
                            if ($this->socket->errCode === SOCKET_ETIMEDOUT) {
                                throw new SocketTimeoutException(
                                    $this->socket->errMsg ?: 'Send timeout',
                                    $this->socket->errCode,
                                );
                            }

                            throw new SocketClosedException(
                                $this->socket->errMsg ?: 'The socket is closed.',
                                $this->socket->errCode,
                            );
                        }
                    }
                } catch (Throwable $exception) {
                    if (! $exception instanceof SocketClosedException
                        && ! $exception instanceof SocketTimeoutException
                    ) {
                        // A failing logger must not escape this raw coroutine before the socket closes.
                        try {
                            $this->logger?->critical((string) $exception);
                        } catch (Throwable $loggingFailure) {
                            FailureReporter::report($loggingFailure);
                        }
                    }

                    $this->close();
                } finally {
                    $this->loop = false;
                }
            });
        } catch (Throwable $exception) {
            // Once the child starts, its finally block owns the loop state.
            if (! $started) {
                $this->loop = false;
            }

            throw $exception;
        }
    }
}
