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
    public const int MAX_UNBREAKABLE_BYTES = 4096;

    private const string OSC8_PATTERN = '\x1B\]8;[^;\x07\x1B]*;[^\x07\x1B]*(?:\x07|\x1B\\\)';

    /**
     * The terminal formatting sequences preserved by decorated renderers.
     */
    public const string TERMINAL_FORMATTING_PATTERN = '(?:\x1B\[[\x30-\x3F]*[\x20-\x2F]*m|' . self::OSC8_PATTERN . ')';

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
        $text = self::filterTerminalControls($text, preserveFormatting: false);
        $text = preg_replace(
            '/(?<!\\\)<(?:\/?(?:info|comment|question|error)|\/|(?i:(?:(?:fg|bg|options|href)=(?:[^;\\\<>]|\\\.)+)(?:;(?:fg|bg|options|href)=(?:[^;\\\<>]|\\\.)+)*))>/',
            '',
            $text,
        );

        return str_replace(['\<', '\>'], ['<', '>'], $text);
    }

    /**
     * Split text into bounded Unicode grapheme units.
     *
     * @return list<string>
     */
    public static function graphemes(string $text): array
    {
        if (preg_match_all('/\X/u', $text, $matches) === false) {
            return mb_str_split($text);
        }

        $graphemes = [];

        foreach ($matches[0] as $grapheme) {
            while (strlen($grapheme) > self::MAX_UNBREAKABLE_BYTES) {
                $length = self::MAX_UNBREAKABLE_BYTES;

                while ((ord($grapheme[$length]) & 0xC0) === 0x80) {
                    --$length;
                }

                $graphemes[] = substr($grapheme, 0, $length);
                $grapheme = substr($grapheme, $length);
            }

            $graphemes[] = $grapheme;
        }

        return $graphemes;
    }

    /**
     * Return the byte length when a fragment continues the trailing grapheme.
     */
    public static function continuedGraphemeBytes(string $text, string $next): ?int
    {
        if (preg_match('/\X\z/u', $text, $matches) !== 1) {
            return null;
        }

        $grapheme = $matches[0] . $next;

        return preg_match('/\A\X\z/u', $grapheme) === 1 ? strlen($grapheme) : null;
    }

    /**
     * Remove terminal controls other than SGR and OSC 8 formatting.
     */
    public static function sanitizeTerminalFormatting(string $text): string
    {
        return self::filterTerminalControls($text, preserveFormatting: true);
    }

    /**
     * Resolve a complete OSC 8 sequence to its active hyperlink state.
     */
    public static function resolveOsc8Link(string $escape): ?string
    {
        if (preg_match('/\A' . self::OSC8_PATTERN . '\z/', $escape) !== 1) {
            return null;
        }

        $body = str_ends_with($escape, "\x07")
            ? substr($escape, 4, -1)
            : substr($escape, 4, -2);
        [, $uri] = explode(';', $body, 2);

        return $uri === '' ? '' : $escape;
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

    /**
     * Filter terminal controls with local recovery from malformed sequences.
     */
    private static function filterTerminalControls(string $text, bool $preserveFormatting): string
    {
        if (! str_contains($text, "\e")) {
            return $text;
        }

        $filtered = '';
        $length = strlen($text);
        $cursor = 0;

        while ($cursor < $length) {
            $escapePosition = strpos($text, "\e", $cursor);

            if ($escapePosition === false) {
                $filtered .= substr($text, $cursor);

                break;
            }

            if ($escapePosition > $cursor) {
                $filtered .= substr($text, $cursor, $escapePosition - $cursor);
                $cursor = $escapePosition;
            }

            if ($cursor + 1 >= $length) {
                break;
            }

            $introducer = $text[$cursor + 1];

            if ($introducer === '[') {
                $position = $cursor + 2;

                while ($position < $length && ord($text[$position]) >= 0x30 && ord($text[$position]) <= 0x3F) {
                    ++$position;
                }

                while ($position < $length && ord($text[$position]) >= 0x20 && ord($text[$position]) <= 0x2F) {
                    ++$position;
                }

                if ($position >= $length) {
                    break;
                }

                if (ord($text[$position]) >= 0x40 && ord($text[$position]) <= 0x7E) {
                    if ($preserveFormatting && $text[$position] === 'm') {
                        $filtered .= substr($text, $cursor, $position - $cursor + 1);
                    }

                    $cursor = $position + 1;

                    continue;
                }

                // Discard only the malformed prefix; the invalid byte may be visible text.
                $cursor = $position;

                continue;
            }

            if ($introducer === ']' || in_array($introducer, ['P', 'X', '^', '_'], true)) {
                $osc = $introducer === ']';
                $position = $cursor + 2;
                $end = null;
                $abortedAt = null;

                while ($position < $length) {
                    if ($text[$position] === "\x07") {
                        $end = $position + 1;

                        break;
                    }

                    if ($text[$position] === "\e") {
                        if ($position + 1 >= $length) {
                            break;
                        }

                        if ($text[$position + 1] === '\\') {
                            $end = $position + 2;

                            break;
                        }

                        $abortedAt = $position;

                        break;
                    }

                    ++$position;
                }

                if ($end !== null) {
                    $sequence = substr($text, $cursor, $end - $cursor);

                    if ($preserveFormatting && $osc && self::resolveOsc8Link($sequence) !== null) {
                        $filtered .= $sequence;
                    }

                    $cursor = $end;

                    continue;
                }

                if ($abortedAt !== null) {
                    // A bare ESC aborts the malformed string and starts a new control.
                    $cursor = $abortedAt;

                    continue;
                }

                break;
            }

            $position = $cursor + 1;

            while ($position < $length && ord($text[$position]) >= 0x20 && ord($text[$position]) <= 0x2F) {
                ++$position;
            }

            if ($position >= $length) {
                break;
            }

            if (ord($text[$position]) >= 0x30 && ord($text[$position]) <= 0x7E) {
                $cursor = $position + 1;

                continue;
            }

            // Reprocess an invalid byte so repeated ESC controls always make progress.
            $cursor = $position;
        }

        return $filtered;
    }
}
