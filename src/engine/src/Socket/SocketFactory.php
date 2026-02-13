<?php

declare(strict_types=1);

namespace Hypervel\Engine\Socket;

use Hypervel\Contracts\Engine\Socket\SocketFactoryInterface;
use Hypervel\Contracts\Engine\Socket\SocketOptionInterface;
use Hypervel\Contracts\Engine\SocketInterface;
use Hypervel\Engine\Exception\SocketConnectException;
use Hypervel\Engine\Socket;

class SocketFactory implements SocketFactoryInterface
{
    /**
     * Create a new socket connection.
     */
    public function make(SocketOptionInterface $option): SocketInterface
    {
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);

        $socket->setSocketOption($option);

        if ($protocol = $option->getProtocol()) {
            $socket->setProtocol($protocol);
        }

        if ($option->getTimeout() === null) {
            $res = $socket->connect($option->getHost(), $option->getPort());
        } else {
            $res = $socket->connect($option->getHost(), $option->getPort(), $option->getTimeout());
        }

        if (! $res) {
            throw new SocketConnectException($socket->errMsg, $socket->errCode);
        }

        return $socket;
    }
}
