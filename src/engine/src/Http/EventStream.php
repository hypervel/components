<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http;

use Hypervel\Contracts\Engine\Http\Writable;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Response;

class EventStream
{
    /**
     * Create a new event stream instance.
     */
    public function __construct(protected Writable $connection, ?Response $response = null)
    {
        /** @var SwooleResponse $socket */
        $socket = $this->connection->getSocket();
        $socket->header('Content-Type', 'text/event-stream; charset=utf-8');
        $socket->header('Transfer-Encoding', 'chunked');
        $socket->header('Cache-Control', 'no-cache');
        foreach ($response?->headers->all() ?? [] as $name => $values) {
            $socket->header($name, implode(', ', $values));
        }
    }

    /**
     * Write data to the event stream.
     */
    public function write(string $data): self
    {
        $this->connection->write($data);
        return $this;
    }

    /**
     * End the event stream.
     */
    public function end(): void
    {
        $this->connection->end();
    }
}
