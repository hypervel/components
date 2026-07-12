<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\WebSocket;

interface ResponseInterface
{
    /**
     * Push a frame to the client.
     */
    public function push(FrameInterface $frame): bool;

    /**
     * Init fd by frame or request and so on,
     * Must be used in swoole process mode.
     */
    public function init(mixed $frame): static;

    /**
     * Get the connection file descriptor.
     */
    public function getFd(): int;

    /**
     * Close the connection.
     */
    public function close(): bool;
}
