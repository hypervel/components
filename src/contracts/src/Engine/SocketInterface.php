<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine;

use Hypervel\Contracts\Engine\Socket\SocketOptionInterface;

interface SocketInterface
{
    /**
     * Set the socket options.
     */
    public function setSocketOption(SocketOptionInterface $option): void;

    /**
     * Get the socket options.
     */
    public function getSocketOption(): ?SocketOptionInterface;

    /**
     * Send all data.
     */
    public function sendAll(string $data, float $timeout = 0): false|int;

    /**
     * Receive the requested amount of data.
     */
    public function recvAll(int $length = 65536, float $timeout = 0): false|string;

    /**
     * Receive a packet.
     */
    public function recvPacket(float $timeout = 0): false|string;

    /**
     * Close the socket.
     */
    public function close(): bool;
}
