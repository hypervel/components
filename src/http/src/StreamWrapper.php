<?php

declare(strict_types=1);

namespace Hypervel\Http;

use GuzzleHttp\Psr7\Stream;
use Hypervel\Coroutine\Coroutine;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Adapt Guzzle's PSR-7 resource bridge so stream callers retain cancellation failures.
 *
 * @see \GuzzleHttp\Psr7\StreamWrapper
 * @see https://www.php.net/streamwrapper
 */
final class StreamWrapper
{
    public const string PROTOCOL = 'hypervel-psr7';

    /** @var resource */
    public $context;

    private StreamInterface $stream;

    private bool $ownsStream = false;

    /** @var string r, r+, or w */
    private string $mode;

    private static bool $registered = false;

    /**
     * Return a borrowed resource without taking ownership of the underlying stream.
     *
     * @return resource
     *
     * @throws InvalidArgumentException if stream is not readable or writable
     */
    public static function getResource(StreamInterface $stream): mixed
    {
        return self::createResource($stream, false);
    }

    /**
     * Transfer ownership of a stream to a PHP resource.
     *
     * Unlike getResource(), closing this resource closes the supplied stream.
     * The caller must relinquish the stream after transferring ownership.
     *
     * @return resource
     */
    public static function detach(StreamInterface $stream): mixed
    {
        if ($stream::class === Stream::class) {
            $resource = $stream->detach();

            if (! is_resource($resource)) {
                throw new RuntimeException('Unable to create stream resource');
            }

            return $resource;
        }

        return self::createResource($stream, true);
    }

    /**
     * Create a stream context for a borrowed PHP stream resource.
     *
     * @return resource
     */
    public static function createStreamContext(StreamInterface $stream): mixed
    {
        return stream_context_create([
            self::PROTOCOL => ['stream' => $stream],
        ]);
    }

    /**
     * Create a PHP resource with the requested ownership.
     *
     * @return resource
     */
    private static function createResource(StreamInterface $stream, bool $ownsStream): mixed
    {
        self::register();

        if ($stream->isReadable()) {
            $mode = $stream->isWritable() ? 'r+' : 'r';
        } elseif ($stream->isWritable()) {
            $mode = 'w';
        } else {
            throw new InvalidArgumentException('The stream must be readable, '
                . 'writable, or both.');
        }

        $context = self::createStreamContext($stream);
        stream_context_set_option($context, self::PROTOCOL, 'owns_stream', $ownsStream);
        $resource = @fopen(self::PROTOCOL . '://stream', $mode, false, $context);

        if ($resource === false) {
            throw new RuntimeException('Unable to create stream resource');
        }

        return $resource;
    }

    /**
     * Register the stream wrapper protocol once per process.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        if (! stream_wrapper_register(self::PROTOCOL, self::class)) {
            throw new RuntimeException('Unable to register the [' . self::PROTOCOL . '] stream wrapper protocol.');
        }

        self::$registered = true;
    }

    /**
     * Open the PSR-7 stream supplied through its resource context.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath = null): bool
    {
        $options = stream_context_get_options($this->context);
        $stream = $options[self::PROTOCOL]['stream'] ?? null;

        if (! $stream instanceof StreamInterface) {
            return false;
        }

        $this->mode = $mode;
        $this->stream = $stream;
        $this->ownsStream = $options[self::PROTOCOL]['owns_stream'] ?? false;

        return true;
    }

    /**
     * Close an owned stream while leaving borrowed bodies with their owner.
     */
    public function stream_close(): void
    {
        if ($this->ownsStream) {
            $this->stream->close();
        }
    }

    /**
     * Read bytes while preserving cancellation through producer cleanup.
     */
    public function stream_read(int $count): string|false
    {
        try {
            return $this->stream->read($count);
        } catch (RuntimeException $e) {
            if (Coroutine::causedByCancellation($e)) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Write bytes to the underlying stream.
     */
    public function stream_write(string $data): int
    {
        try {
            return $this->stream->write($data);
        } catch (RuntimeException $e) {
            if (Coroutine::causedByCancellation($e)) {
                throw $e;
            }

            return -1;
        }
    }

    /**
     * Accept flushing for a PSR-7 stream without a separate flush operation.
     */
    public function stream_flush(): bool
    {
        return true;
    }

    /**
     * Return the current stream position.
     */
    public function stream_tell(): int|false
    {
        try {
            return $this->stream->tell();
        } catch (RuntimeException $e) {
            if (Coroutine::causedByCancellation($e)) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Determine whether the underlying stream has ended.
     */
    public function stream_eof(): bool
    {
        try {
            return $this->stream->eof();
        } catch (RuntimeException $e) {
            if (Coroutine::causedByCancellation($e)) {
                throw $e;
            }

            return true;
        }
    }

    /**
     * Seek within the underlying stream.
     */
    public function stream_seek(int $offset, int $whence): bool
    {
        try {
            $this->stream->seek($offset, $whence);
        } catch (RuntimeException $e) {
            if (Coroutine::causedByCancellation($e)) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * Expose a native descriptor without detaching the retained stream.
     *
     * @return false|resource
     */
    public function stream_cast(int $castAs): mixed
    {
        // Decorator clones share their inner stream; detaching breaks the retained body,
        // and its raw descriptor would bypass decoding or range limits.
        if ($this->stream::class !== Stream::class) {
            return false;
        }

        return (clone $this->stream)->detach() ?? false;
    }

    /**
     * Return metadata for a stream with a known size.
     *
     * @return array{
     *   dev: int,
     *   ino: int,
     *   mode: int,
     *   nlink: int,
     *   uid: int,
     *   gid: int,
     *   rdev: int,
     *   size: int,
     *   atime: int,
     *   mtime: int,
     *   ctime: int,
     *   blksize: int,
     *   blocks: int
     * }|false
     */
    public function stream_stat(): array|false
    {
        try {
            $size = $this->stream->getSize();
        } catch (RuntimeException $e) {
            if (Coroutine::causedByCancellation($e)) {
                throw $e;
            }

            return false;
        }

        if ($size === null) {
            return false;
        }

        static $modeMap = [
            'r' => 33060,
            'rb' => 33060,
            'r+' => 33206,
            'w' => 33188,
            'wb' => 33188,
        ];

        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => $modeMap[$this->mode] ?? 0,
            'nlink' => 0,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => $size,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => 0,
            'blocks' => 0,
        ];
    }

    /**
     * Return placeholder metadata for the wrapper URL.
     *
     * @return array{
     *   dev: int,
     *   ino: int,
     *   mode: int,
     *   nlink: int,
     *   uid: int,
     *   gid: int,
     *   rdev: int,
     *   size: int,
     *   atime: int,
     *   mtime: int,
     *   ctime: int,
     *   blksize: int,
     *   blocks: int
     * }
     */
    public function url_stat(string $path, int $flags): array
    {
        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => 0,
            'nlink' => 0,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => 0,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => 0,
            'blocks' => 0,
        ];
    }
}
