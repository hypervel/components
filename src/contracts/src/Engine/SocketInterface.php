<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Engine\Contract;

use Hyperf\Engine\Contract\Socket\SocketOptionInterface;

interface SocketInterface
{
    public function setSocketOption(SocketOptionInterface $option): void;

    public function getSocketOption(): ?SocketOptionInterface;

    public function sendAll(string $data, float $timeout = 0): false|int;

    public function recvAll(int $length = 65536, float $timeout = 0): false|string;

    public function recvPacket(float $timeout = 0): false|string;

    public function close(): bool;
}
