<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\Socket\SocketOptionInterface;
use Hypervel\Contracts\Engine\SocketInterface;
use Hypervel\Engine\Exceptions\SocketClosedException;
use Hypervel\Engine\Exceptions\SocketTimeoutException;
use Psr\Log\LoggerInterface;
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
     * @throws SocketTimeoutException when send data timeout
     * @throws SocketClosedException when the client is closed
     */
    public function sendAll(string $data, float $timeout = 0): false|int
    {
        $this->loop();

        $res = $this->channel->push([$data, $timeout], $timeout);
        if ($res === false) {
            if ($this->channel->isClosing()) {
                $this->throw && throw new SocketClosedException('The channel is closed.');
            }
            if ($this->channel->isTimeout()) {
                $this->throw && throw new SocketTimeoutException('The channel is full.');
            }

            return false;
        }
        return strlen($data);
    }

    /**
     * Receive all data from the socket.
     *
     * @throws SocketTimeoutException when send data timeout
     * @throws SocketClosedException when the client is closed
     */
    public function recvAll(int $length = 65536, float $timeout = 0): false|string
    {
        $res = $this->socket->recvAll($length, $timeout);
        if ($res === false || $res === '') {
            if ($this->socket->errCode === SOCKET_ETIMEDOUT) {
                $this->throw && throw new SocketTimeoutException(
                    $this->socket->errMsg ?: 'Recv timeout',
                    $this->socket->errCode,
                );
            }

            $this->throw && throw new SocketClosedException(
                $this->socket->errMsg ?: 'The socket is closed.',
                $this->socket->errCode,
            );
        }

        return $res;
    }

    /**
     * Receive a packet from the socket.
     *
     * @throws SocketTimeoutException when send data timeout
     * @throws SocketClosedException when the client is closed
     */
    public function recvPacket(float $timeout = 0): false|string
    {
        $res = $this->socket->recvPacket($timeout);
        if ($res === false || $res === '') {
            if ($this->socket->errCode === SOCKET_ETIMEDOUT) {
                $this->throw && throw new SocketTimeoutException(
                    $this->socket->errMsg ?: 'Recv timeout',
                    $this->socket->errCode,
                );
            }

            $this->throw && throw new SocketClosedException(
                $this->socket->errMsg ?: 'The socket is closed.',
                $this->socket->errCode,
            );
        }

        return $res;
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
        if ($this->loop || $this->channel->isClosing()) {
            return;
        }

        $this->loop = true;

        try {
            Coroutine::create(function (): void {
                try {
                    while (true) {
                        $data = $this->channel->pop(-1);

                        if ($this->channel->isClosing()) {
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
                        $this->logger?->critical((string) $exception);
                    }

                    $this->close();
                } finally {
                    $this->loop = false;
                }
            });
        } catch (Throwable $exception) {
            $this->loop = false;

            throw $exception;
        }
    }
}
