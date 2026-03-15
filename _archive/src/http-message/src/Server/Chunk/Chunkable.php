<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Server\Chunk;

interface Chunkable
{
    /**
     * Write a chunk of data to the response.
     */
    public function write(string $data): bool;
}
