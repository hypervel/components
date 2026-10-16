<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Contracts;

use Closure;

interface Transport
{
    /**
     * Register the incoming message handler.
     */
    public function onReceive(Closure $handler): void;

    /**
     * Run the transport.
     */
    public function run(); // @phpstan-ignore-line

    /**
     * Send an encoded protocol message.
     */
    public function send(string $message): void;

    /**
     * Send messages produced by a streaming callback.
     */
    public function stream(Closure $stream): void;
}
