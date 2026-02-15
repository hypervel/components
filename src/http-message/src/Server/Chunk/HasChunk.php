<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Server\Chunk;

use Hypervel\Contracts\Engine\Http\Writable;

trait HasChunk
{
    /**
     * Write a chunk of content to the connection.
     */
    public function write(string $content): bool
    {
        if (isset($this->connection)) {
            return $this->connection->write($content);
        }

        return false;
    }
}
