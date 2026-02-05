<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Socket;

use Hypervel\Contracts\Engine\SocketInterface;

interface SocketFactoryInterface
{
    public function make(SocketOptionInterface $option): SocketInterface;
}
