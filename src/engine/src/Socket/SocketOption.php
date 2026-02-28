<?php

declare(strict_types=1);

namespace Hypervel\Engine\Socket;

use Hypervel\Contracts\Engine\Socket\SocketOptionInterface;

class SocketOption implements SocketOptionInterface
{
    /**
     * Create a new socket option instance.
     */
    public function __construct(
        protected string $host,
        protected int $port,
        protected ?float $timeout = null,
        protected array $protocol = []
    ) {
    }

    /**
     * Get the host.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Get the port.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Get the connection timeout in seconds.
     */
    public function getTimeout(): ?float
    {
        return $this->timeout;
    }

    /**
     * Get the protocol configuration.
     */
    public function getProtocol(): array
    {
        return $this->protocol;
    }
}
