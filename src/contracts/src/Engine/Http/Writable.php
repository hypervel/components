<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http;

interface Writable
{
    /**
     * Get the underlying socket.
     */
    public function getSocket(): mixed;

    /**
     * Write data to the socket.
     */
    public function write(string $data): bool;

    /**
     * End the response.
     */
    public function end(): ?bool;
}
