<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Stream;

use Exception;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Stringable;

use function clearstatcache;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function fwrite;
use function is_resource;
use function is_string;
use function stream_get_contents;
use function stream_get_meta_data;
use function var_export;

use const SEEK_CUR;
use const SEEK_SET;

/**
 * Code Taken from Nyholm/psr7.
 * Author: Michael Dowling and contributors to guzzlehttp/psr7
 * Author: Tobias Nyholm <tobias.nyholm@gmail.com>
 * Author: Martijn van der Ven <martijn@vanderven.se>.
 * @license https://github.com/Nyholm/psr7/blob/master/LICENSE
 */
final class StandardStream implements Stringable, StreamInterface
{
    /** @var array Hash of readable and writable stream types */
    private const READ_WRITE_HASH = [
        'read' => [
            'r' => true, 'w+' => true, 'r+' => true, 'x+' => true, 'c+' => true,
            'rb' => true, 'w+b' => true, 'r+b' => true, 'x+b' => true,
            'c+b' => true, 'rt' => true, 'w+t' => true, 'r+t' => true,
            'x+t' => true, 'c+t' => true, 'a+' => true,
        ],
        'write' => [
            'w' => true, 'w+' => true, 'rw' => true, 'r+' => true, 'x+' => true,
            'c+' => true, 'wb' => true, 'w+b' => true, 'r+b' => true,
            'x+b' => true, 'c+b' => true, 'w+t' => true, 'r+t' => true,
            'x+t' => true, 'c+t' => true, 'a' => true, 'a+' => true,
        ],
    ];

    /** @var null|resource */
    private mixed $stream = null;

    private bool $seekable = false;

    private bool $readable = false;

    private bool $writable = false;

    private mixed $uri = null;

    private ?int $size = null;

    private function __construct()
    {
    }

    /**
     * Close the stream when destructed.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Read all data from the stream into a string.
     */
    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->seek(0);
            }

            return $this->getContents();
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Create a new PSR-7 stream.
     *
     * @param resource|StreamInterface|string $body
     */
    public static function create(mixed $body = ''): StreamInterface
    {
        if ($body instanceof StreamInterface) {
            return $body;
        }

        if (is_string($body)) {
            $resource = fopen('php://temp', 'rw+');
            fwrite($resource, $body);
            $body = $resource;
        }

        if (is_resource($body)) {
            $new = new self();
            $new->stream = $body;
            $meta = stream_get_meta_data($new->stream);
            $new->seekable = $meta['seekable'] && fseek($new->stream, 0, SEEK_CUR) === 0;
            $new->readable = isset(self::READ_WRITE_HASH['read'][$meta['mode']]);
            $new->writable = isset(self::READ_WRITE_HASH['write'][$meta['mode']]);
            $new->uri = $new->getMetadata('uri');

            return $new;
        }

        throw new InvalidArgumentException('First argument to Stream::create() must be a string, resource or StreamInterface.');
    }

    /**
     * Close the stream and any underlying resources.
     */
    public function close(): void
    {
        if (isset($this->stream)) {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }
            $this->detach();
        }
    }

    /**
     * Separate the underlying resource from the stream.
     *
     * @return resource|null
     */
    public function detach(): mixed
    {
        if (! isset($this->stream)) {
            return null;
        }

        $result = $this->stream;
        unset($this->stream);
        $this->size = $this->uri = null;
        $this->readable = $this->writable = $this->seekable = false;

        return $result;
    }

    /**
     * Get the size of the stream if known.
     */
    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if (! isset($this->stream)) {
            return null;
        }

        // Clear the stat cache if the stream has a URI
        if ($this->uri) {
            clearstatcache(true, $this->uri);
        }

        $stats = fstat($this->stream);
        if (isset($stats['size'])) {
            $this->size = $stats['size'];

            return $this->size;
        }

        return null;
    }

    /**
     * Return the current position of the file read/write pointer.
     */
    public function tell(): int
    {
        if (false === $result = ftell($this->stream)) {
            throw new RuntimeException('Unable to determine stream position');
        }

        return $result;
    }

    /**
     * Determine if the stream is at the end.
     */
    public function eof(): bool
    {
        return ! $this->stream || feof($this->stream);
    }

    /**
     * Determine if the stream is seekable.
     */
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    /**
     * Seek to a position in the stream.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (! $this->seekable) {
            throw new RuntimeException('Stream is not seekable');
        }

        if (fseek($this->stream, $offset, $whence) === -1) {
            throw new RuntimeException('Unable to seek to stream position ' . $offset . ' with whence ' . var_export($whence, true));
        }
    }

    /**
     * Seek to the beginning of the stream.
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
     */
    public function write(string $string): int
    {
        if (! $this->writable) {
            throw new RuntimeException('Cannot write to a non-writable stream');
        }

        // We can't know the size after writing anything
        $this->size = null;

        if (false === $result = fwrite($this->stream, $string)) {
            throw new RuntimeException('Unable to write to stream');
        }

        return $result;
    }

    /**
     * Determine if the stream is readable.
     */
    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * Read data from the stream.
     */
    public function read(int $length): string
    {
        if (! $this->readable) {
            throw new RuntimeException('Cannot read from non-readable stream');
        }

        return fread($this->stream, $length);
    }

    /**
     * Get the remaining contents of the stream as a string.
     */
    public function getContents(): string
    {
        if (! isset($this->stream)) {
            throw new RuntimeException('Unable to read stream contents');
        }

        if (false === $contents = stream_get_contents($this->stream)) {
            throw new RuntimeException('Unable to read stream contents');
        }

        return $contents;
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     */
    public function getMetadata(?string $key = null): mixed
    {
        if (! isset($this->stream)) {
            return $key ? null : [];
        }

        $meta = stream_get_meta_data($this->stream);

        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }
}
