<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolErrorReporter;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class LeasedStream
{
    public const PROTOCOL = 'hypervel-leased';

    /** @var resource */
    public $context;

    /** @var null|resource */
    private mixed $inner = null;

    private ?Lease $lease = null;

    private static bool $registered = false;

    /**
     * Wrap a resource so its lease is finalized when the stream closes.
     *
     * @param resource $resource
     * @return resource
     */
    public static function wrap(mixed $resource, Lease $lease): mixed
    {
        if (! is_resource($resource)) {
            throw new InvalidArgumentException('LeasedStream::wrap() expects an open stream resource.');
        }

        try {
            self::register();

            $context = stream_context_create([
                self::PROTOCOL => [
                    'inner' => $resource,
                    'lease' => $lease,
                ],
            ]);
            $stream = fopen(self::PROTOCOL . '://stream', 'r', false, $context);

            if ($stream === false) {
                throw new RuntimeException('Unable to open the leased stream wrapper.');
            }

            return $stream;
        } catch (Throwable $primaryException) {
            self::closeResource($resource);

            try {
                $lease->release();
            } catch (Throwable $cleanupException) {
                PoolErrorReporter::report($cleanupException);
            }

            throw $primaryException;
        }
    }

    /**
     * Register the stream wrapper protocol once per process.
     */
    private static function register(): void
    {
        if (self::$registered) {
            return;
        }

        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            throw new RuntimeException(
                'The [' . self::PROTOCOL . '] stream wrapper protocol is already registered by other code; '
                . 'leased streams cannot hand their lease to a foreign wrapper.'
            );
        }

        if (! stream_wrapper_register(self::PROTOCOL, self::class)) {
            throw new RuntimeException('Unable to register the [' . self::PROTOCOL . '] stream wrapper.');
        }

        self::$registered = true;
    }

    /**
     * Open a leased stream from its private stream context.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $context = stream_context_get_options($this->context)[self::PROTOCOL] ?? null;

        if (! is_array($context)
            || ! is_resource($context['inner'] ?? null)
            || ! ($context['lease'] ?? null) instanceof Lease
        ) {
            return false;
        }

        $this->inner = $context['inner'];
        $this->lease = $context['lease'];

        return true;
    }

    /**
     * Read from the inner stream.
     */
    public function stream_read(int $count): false|string
    {
        return is_resource($this->inner) ? fread($this->inner, $count) : false;
    }

    /**
     * Determine if the inner stream is at end-of-file.
     */
    public function stream_eof(): bool
    {
        return ! is_resource($this->inner) || feof($this->inner);
    }

    /**
     * Seek to a position in the inner stream.
     */
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return is_resource($this->inner) && fseek($this->inner, $offset, $whence) === 0;
    }

    /**
     * Get the current inner-stream position.
     */
    public function stream_tell(): false|int
    {
        return is_resource($this->inner) ? ftell($this->inner) : false;
    }

    /**
     * Get statistics for the inner stream.
     */
    public function stream_stat(): array|false
    {
        return is_resource($this->inner) ? fstat($this->inner) : false;
    }

    /**
     * Expose the inner stream to resource-casting APIs.
     *
     * @return false|resource
     */
    public function stream_cast(int $castAs): mixed
    {
        return is_resource($this->inner) ? $this->inner : false;
    }

    /**
     * Apply supported stream options to the inner stream.
     */
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        if (! is_resource($this->inner)) {
            return false;
        }

        return match ($option) {
            STREAM_OPTION_BLOCKING => stream_set_blocking($this->inner, (bool) $arg1),
            STREAM_OPTION_READ_TIMEOUT => stream_set_timeout($this->inner, $arg1, $arg2 ?? 0),
            STREAM_OPTION_WRITE_BUFFER => match ($arg1) {
                STREAM_BUFFER_NONE => stream_set_write_buffer($this->inner, 0) === 0,
                STREAM_BUFFER_FULL => stream_set_write_buffer($this->inner, $arg2 ?? 0) === 0,
                default => false,
            },
            default => false,
        };
    }

    /**
     * Close the inner stream and finalize its lease without throwing.
     */
    public function stream_close(): void
    {
        $this->finalize();
    }

    /**
     * Close a resource without allowing cleanup failures to escape.
     */
    private static function closeResource(mixed $resource): void
    {
        if (! is_resource($resource)) {
            return;
        }

        try {
            fclose($resource);
        } catch (Throwable $exception) {
            PoolErrorReporter::report($exception);
        }
    }

    /**
     * Close and release exactly once.
     */
    private function finalize(): void
    {
        $inner = $this->inner;
        $lease = $this->lease;
        $this->inner = null;
        $this->lease = null;

        self::closeResource($inner);

        if ($lease !== null) {
            try {
                $lease->release();
            } catch (Throwable $exception) {
                PoolErrorReporter::report($exception);
            }
        }
    }
}
