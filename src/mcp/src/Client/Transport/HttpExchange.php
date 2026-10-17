<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Transport;

use Hypervel\Http\Client\Response;
use Hypervel\Mcp\Exceptions\ClientException;

/**
 * Own the response independently of later connection negotiations.
 *
 * @internal
 */
class HttpExchange
{
    public ?Response $response = null;

    /** @var list<string> */
    public array $queue = [];

    public bool $closed = false;

    public bool $streaming = false;

    /**
     * Capture the connection state used to send this exchange.
     */
    public function __construct(
        public readonly int $generation,
        public readonly bool $initializing,
    ) {
    }

    /**
     * Reject output that arrived after the exchange was disconnected.
     */
    public function ensureOpen(): void
    {
        if ($this->closed) {
            throw new ClientException('The HTTP exchange was closed before its response was consumed.');
        }
    }

    /**
     * Close the response and release retained data.
     */
    public function close(): void
    {
        $this->closed = true;
        $response = $this->response;
        $this->response = null;
        $this->queue = [];
        $response?->close();
    }
}
