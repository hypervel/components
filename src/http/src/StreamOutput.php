<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Hypervel\Contracts\Engine\Http\Writable;

class StreamOutput
{
    public function __construct(
        protected Writable $connection
    ) {
    }

    /**
     * Write a chunk of content to the client via the Swoole connection.
     */
    public function write(string $content): bool
    {
        return $this->connection->write($content);
    }
}
