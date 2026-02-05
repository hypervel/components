<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http;

use Hypervel\Contracts\Engine\Http\Writable;
use Swoole\Http\Response;

class WritableConnection implements Writable
{
    /**
     * Create a new writable connection instance.
     */
    public function __construct(protected Response $response)
    {
    }

    /**
     * Write data to the connection.
     */
    public function write(string $data): bool
    {
        return $this->response->write($data);
    }

    /**
     * Get the underlying socket.
     *
     * @return Response
     */
    public function getSocket(): mixed
    {
        return $this->response;
    }

    /**
     * End the connection.
     */
    public function end(): ?bool
    {
        return $this->response->end();
    }
}
