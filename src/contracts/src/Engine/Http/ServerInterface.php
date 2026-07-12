<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http;

interface ServerInterface
{
    /**
     * Set the request handler.
     */
    public function handle(callable $callable): static;

    /**
     * Start the server.
     */
    public function start(): void;

    /**
     * Close the server.
     */
    public function close(): bool;
}
