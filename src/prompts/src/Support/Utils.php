<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Support;

use Closure;
use RuntimeException;

/**
 * @internal
 */
class Utils
{
    /**
     * The largest chunk offered to a single write attempt.
     */
    private const int WRITE_CHUNK_BYTES = 65536;

    /**
     * The stream type that always accepts a full write and cannot be selected on.
     */
    private const string MEMORY_STREAM_TYPE = 'MEMORY';

    /**
     * Determine if all items in an array match a truth test.
     *
     * @param array<array-key, mixed> $values
     */
    public static function allMatch(array $values, Closure $callback): bool
    {
        foreach ($values as $key => $value) {
            if (! $callback($value, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the last item from an array or null if it doesn't exist.
     *
     * @param array<array-key, mixed> $array
     */
    public static function last(array $array): mixed
    {
        return array_reverse($array)[0] ?? null;
    }

    /**
     * Returns the key of the first element in the array that satisfies the callback.
     *
     * @param array<array-key, mixed> $array
     */
    public static function search(array $array, Closure $callback): false|int|string
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $key;
            }
        }

        return false;
    }

    /**
     * Strip terminal escape sequences and Symfony style tags.
     */
    public static function stripEscapeSequences(string $text): string
    {
        $text = preg_replace('/\e\[[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]/', '', $text);
        $text = preg_replace('/\e\][^\x07\e]*(?:\x07|\e\\\)/', '', $text);
        $text = preg_replace('/<(info|comment|question|error)>(.*?)<\/\1>/', '$2', $text);

        return preg_replace('/<(?:(?:[fb]g|options)=[a-z,;]+)+>(.*?)<\/>/i', '$1', $text);
    }

    /**
     * Write an entire payload to a stream, waiting up to a timeout for a blocked reader.
     *
     * The stream is driven in non-blocking mode because stream_set_timeout() only
     * governs reads. A blocking write into a full buffer parks in the underlying
     * send syscall on macOS and BSD, where no timeout applies and no signal can
     * interrupt it, so the caller would wait for the reader forever.
     *
     * @param resource $stream
     * @param float $timeout seconds to wait for a stalled reader to accept more output
     */
    public static function writeAll($stream, string $payload, float $timeout = 10.0): void
    {
        $length = strlen($payload);

        if ($length === 0) {
            return;
        }

        $metadata = stream_get_meta_data($stream);

        // Selectable streams are driven non-blocking; the rest keep their original mode.
        $selectable = $metadata['stream_type'] !== self::MEMORY_STREAM_TYPE
            && @stream_set_blocking($stream, false);
        $blocking = self::isBlocking($metadata);
        $offset = 0;

        try {
            while ($offset < $length) {
                // Capping the chunk keeps the copy proportional to what a write can accept.
                $written = @fwrite($stream, substr($payload, $offset, self::WRITE_CHUNK_BYTES));

                if (is_int($written) && $written > 0) {
                    $offset += $written;

                    continue;
                }

                // Only a failed write reports closure; zero simply means the buffer is full.
                if ($written === false) {
                    throw new RuntimeException(
                        feof($stream)
                            ? 'The prompt renderer closed while receiving output.'
                            : 'Unable to write output to the prompt renderer.',
                    );
                }

                // A blocking stream returning zero cannot be waited on without stalling.
                if (! $selectable) {
                    throw new RuntimeException('Unable to write output to the prompt renderer.');
                }

                if (! self::awaitWritable($stream, $timeout)) {
                    throw new RuntimeException('The prompt renderer timed out while receiving output.');
                }
            }
        } finally {
            // Only restore blocking mode when the caller was relying on it.
            if ($selectable && $blocking) {
                @stream_set_blocking($stream, true);
            }
        }
    }

    /**
     * Determine whether a stream was in blocking mode.
     *
     * @param array<string, mixed> $metadata
     */
    private static function isBlocking(array $metadata): bool
    {
        // php://temp omits the blocking state entirely, so treat it as the stream default.
        return (bool) ($metadata['blocked'] ?? true);
    }

    /**
     * Wait for a stream to accept more output.
     *
     * @param resource $stream
     */
    private static function awaitWritable($stream, float $timeout): bool
    {
        $read = null;
        $except = null;
        $write = [$stream];
        $seconds = (int) $timeout;

        return (bool) @stream_select(
            $read,
            $write,
            $except,
            $seconds,
            (int) round(($timeout - $seconds) * 1_000_000),
        );
    }
}
