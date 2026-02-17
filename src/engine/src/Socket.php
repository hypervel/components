<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\Socket\SocketOptionInterface;
use Hypervel\Contracts\Engine\SocketInterface;

class Socket extends \Swoole\Coroutine\Socket implements SocketInterface
{
    protected ?SocketOptionInterface $option = null;

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
}
