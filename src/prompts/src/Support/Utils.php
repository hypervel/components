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
     * Write an entire payload to a stream.
     *
     * @param resource $stream
     */
    public static function writeAll($stream, string $payload): void
    {
        $length = strlen($payload);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($stream, substr($payload, $offset));

            if (is_int($written) && $written > 0) {
                $offset += $written;

                if ($offset === $length) {
                    continue;
                }
            }

            $metadata = stream_get_meta_data($stream);

            // A timed-out stream stays latched and must not be reused after this failure.
            if ($metadata['timed_out']) {
                throw new RuntimeException('The prompt renderer timed out while receiving output.');
            }

            if ($written === false || $written === 0) {
                throw new RuntimeException(
                    $metadata['eof']
                        ? 'The prompt renderer closed while receiving output.'
                        : 'Unable to write output to the prompt renderer.',
                );
            }
        }
    }
}
