<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Socket;

use Hypervel\Contracts\Engine\SocketInterface;

interface SocketFactoryInterface
{
    /**
     * Create a socket.
     */
    public function make(SocketOptionInterface $option): SocketInterface;
}
