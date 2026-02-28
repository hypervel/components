<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine;

use Hypervel\Contracts\Engine\Socket\SocketOptionInterface;

interface SocketInterface
{
    public function setSocketOption(SocketOptionInterface $option): void;

    public function getSocketOption(): ?SocketOptionInterface;

    public function sendAll(string $data, float $timeout = 0): false|int;

    public function recvAll(int $length = 65536, float $timeout = 0): false|string;

    public function recvPacket(float $timeout = 0): false|string;

    public function close(): bool;
}
