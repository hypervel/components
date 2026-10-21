<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use Generator;
use Hypervel\Contracts\Container\Transient;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 */
class LineReader implements Transient
{
    private const int READ_SIZE = 8192;

    private string $buffer = '';

    private int $offset = 0;

    /**
     * Read a line including its LF, or the final unterminated line at EOF.
     *
     * Return an empty string while no complete line is available; check eof()
     * to distinguish this from the end of the body.
     */
    public function readLine(StreamInterface $stream): string
    {
        $searchOffset = $this->offset;

        while (($end = strpos($this->buffer, "\n", $searchOffset)) === false && ! $stream->eof()) {
            if ($this->offset !== 0) {
                $this->buffer = substr($this->buffer, $this->offset);
                $this->offset = 0;
            }

            $searchOffset = strlen($this->buffer);

            // Accept short reads immediately; filling the chunk would delay live events.
            $chunk = $stream->read(self::READ_SIZE);
            $this->buffer .= $chunk;

            if ($chunk === '') {
                break;
            }
        }

        if ($end === false && ! $stream->eof()) {
            return '';
        }

        $end = $end === false ? strlen($this->buffer) : $end + 1;
        $line = substr($this->buffer, $this->offset, $end - $this->offset);
        $this->offset = $end;

        if ($this->offset === strlen($this->buffer) || $this->offset >= self::READ_SIZE) {
            // Release a consumed chunk or prefix so a finished long line is not retained.
            $this->buffer = substr($this->buffer, $this->offset);
            $this->offset = 0;
        }

        return $line;
    }

    /**
     * Read lines without LF or its preceding CR, preserving an unterminated line.
     *
     * @return Generator<int, string, void, void>
     */
    public static function lines(StreamInterface $stream): Generator
    {
        $buffer = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(self::READ_SIZE);
            $start = 0;

            while (($end = strpos($chunk, "\n", $start)) !== false) {
                $line = $buffer . substr($chunk, $start, $end - $start);
                $buffer = '';

                yield str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;

                // Release the untrimmed CRLF line before accumulating the next record.
                unset($line);
                $start = $end + 1;
            }

            $buffer .= substr($chunk, $start);
        }

        if ($buffer !== '') {
            yield $buffer;
        }
    }
}
