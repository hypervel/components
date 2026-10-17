<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Contracts;

interface IsolatesExchanges
{
    /**
     * End the calling coroutine's exchange and release its response.
     */
    public function endExchange(): void;
}
