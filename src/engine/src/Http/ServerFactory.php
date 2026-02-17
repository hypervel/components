<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Engine\Http\ServerFactoryInterface;
use Hypervel\Contracts\Engine\Http\ServerInterface;

class ServerFactory implements ServerFactoryInterface
{
    /**
     * Create a new server factory instance.
     */
    public function __construct(protected StdoutLoggerInterface $logger)
    {
    }

    /**
     * Create a new server instance.
     */
    public function make(string $name, int $port = 0): ServerInterface
    {
        $server = new Server($this->logger);

        return $server->bind($name, $port);
    }
}
