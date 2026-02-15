<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Stream;

use BadMethodCallException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Stringable;
use Throwable;

class SwooleStream implements StreamInterface, Stringable
{
    protected int $size;

    protected bool $writable;

    /**
     * Create a new Swoole stream instance.
     */
    public function __construct(protected string $contents = '')
    {
        $this->size = strlen($this->contents);
        $this->writable = true;
    }

    /**
     * Read all data from the stream into a string.
     *
     * Attempts to seek to the beginning before reading until the end is reached.
     * Warning: This could attempt to load a large amount of data into memory.
     * Must not raise an exception to conform with PHP's string casting operations.
     *
     * @see http://php.net/manual/en/language.oop5.magic.php#object.tostring
     */
    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Close the stream and any underlying resources.
     */
    public function close(): void
    {
        $this->detach();
    }

    /**
     * Separate the underlying resource from the stream.
     *
     * After the stream has been detached, the stream is in an unusable state.
     *
     * @return resource|null
     */
    public function detach(): mixed
    {
        $this->contents = '';
        $this->size = 0;
        $this->writable = false;

        return null;
    }

    /**
     * Get the size of the stream if known.
     */
    public function getSize(): ?int
    {
        if (! $this->size) {
            $this->size = strlen($this->getContents());
        }
        return $this->size;
    }

    /**
     * Return the current position of the file read/write pointer.
     *
     * @return int position of the file pointer
     * @throws RuntimeException on error
     */
    public function tell(): int
    {
        throw new RuntimeException('Cannot determine the position of a SwooleStream');
    }

    /**
     * Determine if the stream is at the end.
     */
    public function eof(): bool
    {
        return $this->getSize() === 0;
    }

    /**
     * Determine if the stream is seekable.
     */
    public function isSeekable(): bool
    {
        return false;
    }

    /**
     * Seek to a position in the stream.
     *
     * @see http://www.php.net/manual/en/function.fseek.php
     * @param int $whence Specifies how the cursor position will be calculated
     *                    based on the seek offset. Valid values are identical to the built-in
     *                    PHP $whence values for `fseek()`. SEEK_SET: Set position equal to
     *                    offset bytes SEEK_CUR: Set position to current location plus offset
     *                    SEEK_END: Set position to end-of-stream plus offset.
     * @throws RuntimeException on failure
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Cannot seek a SwooleStream');
    }

    /**
     * Seek to the beginning of the stream.
     * If the stream is not seekable, this method will raise an exception;
     * otherwise, it will perform a seek(0).
     *
     * @throws RuntimeException on failure
     * @see http://www.php.net/manual/en/function.fseek.php
     * @see seek()
     */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * Determine if the stream is writable.
     */
    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * Write data to the stream.
     *
     * @return int the number of bytes written to the stream
     * @throws RuntimeException on failure
     */
    public function write(string $string): int
    {
        if (! $this->writable) {
            throw new RuntimeException('Cannot write to a non-writable stream');
        }

        $size = strlen($string);

        $this->contents .= $string;
        $this->size += $size;

        return $size;
    }

    /**
     * Determine if the stream is readable.
     */
    public function isReadable(): bool
    {
        return true;
    }

    /**
     * Read data from the stream.
     *
     * @param int $length Read up to $length bytes from the object and return
     *                    them. Fewer than $length bytes may be returned if underlying stream
     *                    call returns fewer bytes.
     * @return string returns the data read from the stream, or an empty string
     *                if no bytes are available
     * @throws RuntimeException if an error occurs
     */
    public function read(int $length): string
    {
        if ($length >= $this->getSize()) {
            $result = $this->contents;
            $this->contents = '';
            $this->size = 0;
        } else {
            $result = substr($this->contents, 0, $length);
            $this->contents = substr($this->contents, $length);
            $this->size = $this->getSize() - $length;
        }

        return $result;
    }

    /**
     * Return the remaining contents in a string.
     *
     * @throws RuntimeException if unable to read or an error occurs while
     *                          reading
     */
    public function getContents(): string
    {
        return $this->contents;
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @see http://php.net/manual/en/function.stream-get-meta-data.php
     * @param string $key specific metadata to retrieve
     * @return null|array|mixed Returns an associative array if no key is
     *                          provided. Returns a specific key value if a key is provided and the
     *                          value is found, or null if the key is not found.
     */
    public function getMetadata(?string $key = null): mixed
    {
        throw new BadMethodCallException('Not implemented');
    }
}
