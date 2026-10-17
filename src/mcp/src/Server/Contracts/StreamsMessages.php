<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Contracts;

use Closure;

interface StreamsMessages
{
    /**
     * Stream a producer that sends messages before yielding control.
     *
     * @param Closure(): iterable<mixed> $producer
     */
    public function streamMessages(Closure $producer, bool $cancelOnDisconnect): void;
}
