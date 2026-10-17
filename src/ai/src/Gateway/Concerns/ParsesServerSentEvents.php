<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Concerns;

use Generator;
use Hypervel\Http\Client\LineReader;
use Psr\Http\Message\StreamInterface;
use WeakMap;

trait ParsesServerSentEvents
{
    /**
     * Isolate pending bytes while readLine overrides still receive the original stream.
     *
     * @var null|WeakMap<StreamInterface, LineReader>
     */
    private ?WeakMap $lineReaders = null;

    /**
     * Parse an SSE stream body into decoded JSON data objects.
     */
    protected function parseServerSentEvents(StreamInterface $streamBody): Generator
    {
        while (($line = $this->readLine($streamBody)) !== '' || ! $streamBody->eof()) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));

            if ($data === '[DONE]') {
                return;
            }

            $decoded = json_decode($data, true);

            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                yield $decoded;
            }
        }
    }

    /**
     * Read one line without waiting for a larger body buffer to fill.
     */
    protected function readLine(StreamInterface $streamBody): string
    {
        $this->lineReaders ??= new WeakMap;

        return ($this->lineReaders[$streamBody] ??= new LineReader)->readLine($streamBody);
    }
}
