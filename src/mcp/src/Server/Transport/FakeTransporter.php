<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Transport;

use Closure;
use Hypervel\Http\Response;
use Hypervel\Mcp\Server\Contracts\Transport;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FakeTransporter implements Transport
{
    /**
     * Ignore handler registration for direct method tests.
     */
    public function onReceive(Closure $handler): void
    {
    }

    /**
     * Ignore outgoing transport messages.
     */
    public function send(string $message): void
    {
    }

    /**
     * Reject transport execution in direct method tests.
     */
    public function run(): Response|StreamedResponse
    {
        throw new LogicException('Not implemented.');
    }

    /**
     * Ignore streaming callbacks.
     */
    public function stream(Closure $stream): void
    {
    }
}
