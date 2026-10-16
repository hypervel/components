<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Closure;
use Hypervel\Mcp\Server\Contracts\Transport;

class ArrayTransport implements Transport
{
    public ?Closure $handler = null;

    public array $sent = [];

    /**
     * Register the incoming message handler.
     */
    public function onReceive(Closure $handler): void
    {
        $this->handler = $handler;
    }

    /**
     * Run the in-memory transport.
     */
    public function run(): void
    {
    }

    /**
     * Record an outgoing message.
     */
    public function send(string $message): void
    {
        $this->sent[] = $message;
    }

    /**
     * Consume the stream synchronously.
     */
    public function stream(Closure $stream): void
    {
        $stream();
    }
}
