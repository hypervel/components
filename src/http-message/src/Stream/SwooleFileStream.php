<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Stream;

use BadMethodCallException;
use Hypervel\HttpServer\Exceptions\Http\FileException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SplFileInfo;
use Stringable;
use Throwable;

class SwooleFileStream implements StreamInterface, FileInterface, Stringable
{
    protected int $size;

    protected SplFileInfo $file;

    /**
     * Create a new Swoole file stream instance.
     */
    public function __construct(SplFileInfo|string $file)
    {
        if (! $file instanceof SplFileInfo) {
            $file = new SplFileInfo($file);
        }
        if (! $file->isReadable()) {
            throw new FileException('File must be readable.');
        }
        $this->file = $file;
        $this->size = $file->getSize();
    }

    /**
     * Read all data from the stream into a string.
     *
     * Attempts to seek to the beginning before reading until the end is reached.
     * Warning: This could attempt to load a large amount of data into memory.
     * Must not raise an exception to conform with PHP's string casting operations.
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
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * Separate the underlying resource from the stream.
     *
     * @return resource|null
     */
    public function detach(): mixed
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * Get the size of the stream if known.
     */
    public function getSize(): ?int
    {
        if (! $this->size) {
            $this->size = filesize($this->getContents());
        }
        return $this->size;
    }

    /**
     * Return the current position of the file read/write pointer.
     */
    public function tell(): int
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * Determine if the stream is at the end.
     */
    public function eof(): bool
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * Determine if the stream is seekable.
     */
    public function isSeekable(): bool
    {
        throw new BadMethodCallException('Not implemented');
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
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * Seek to the beginning of the stream.
     *
     * If the stream is not seekable, this method will raise an exception;
     * otherwise, it will perform a seek(0).
     *
     * @see http://www.php.net/manual/en/function.fseek.php
     * @see seek()
     */
    public function rewind(): void
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * Determine if the stream is writable.
     */
    public function isWritable(): bool
    {
        return false;
    }

    /**
     * Write data to the stream.
     *
     * @throws RuntimeException on failure
     */
    public function write(string $string): int
    {
        throw new BadMethodCallException('Not implemented');
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
     * @param int $length Read up to $length bytes from the object and return them.
     *                    Fewer than $length bytes may be returned if underlying stream
     *                    call returns fewer bytes.
     * @return string the data read from the stream, or an empty string
     *                if no bytes are available
     * @throws RuntimeException if an error occurs
     */
    public function read(int $length): string
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * Return the remaining contents in a string.
     *
     * @throws RuntimeException if unable to read or an error occurs while
     *                          reading
     */
    public function getContents(): string
    {
        return $this->getFilename();
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
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

    /**
     * Get the filename of the file stream.
     */
    public function getFilename(): string
    {
        return $this->file->getPathname();
    }
}
