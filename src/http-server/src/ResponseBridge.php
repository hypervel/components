<?php

declare(strict_types=1);

namespace Hypervel\HttpServer;

use Hypervel\Contracts\Http\HasTrailers;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\Response as HypervelResponse;
use RuntimeException;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ResponseBridge
{
    /**
     * Trailer fields that cannot be emitted safely as HTTP trailers.
     *
     * @var list<string>
     */
    protected const FORBIDDEN_TRAILER_NAMES = [
        'host',
        'content-length',
        'transfer-encoding',
        'trailer',
        'te',
        'connection',
        'keep-alive',
        'proxy-connection',
        'upgrade',
    ];

    /**
     * Send an HttpFoundation response through Swoole.
     */
    public static function send(Response $response, SwooleResponse $swooleResponse, bool $withBody = true): void
    {
        if ($response instanceof HypervelResponse && $response->isStreamed()) {
            // Response::stream() writes headers and content itself but leaves
            // finalization to the bridge. gRPC responses never use this escape hatch.
            $swooleResponse->end();

            return;
        }

        if ($response instanceof HasTrailers && $response instanceof BinaryFileResponse) {
            throw new RuntimeException('Binary file responses cannot emit trailers.');
        }

        if (! $withBody) {
            static::announceTrailers($response);
            static::sendStatusAndHeaders($response, $swooleResponse);
            static::end($swooleResponse);

            return;
        }

        if ($response instanceof BinaryFileResponse) {
            static::sendStatusAndHeaders($response, $swooleResponse);
            static::sendFile($swooleResponse, $response->getFile()->getPathname());

            return;
        }

        if ($response instanceof StreamedResponse) {
            // Swoole's write() uses chunked transfer encoding. Content-Length
            // and Transfer-Encoding headers conflict with this — Swoole raises
            // an ErrorException if Content-Length is set before write().
            // See: https://github.com/laravel/octane/issues/670
            $response->headers->remove('Content-Length');
            $response->headers->remove('Transfer-Encoding');

            static::announceTrailers($response);
            static::sendStatusAndHeaders($response, $swooleResponse);
            static::sendStreamedContent($response, $swooleResponse);

            return;
        }

        static::announceTrailers($response);
        static::sendStatusAndHeaders($response, $swooleResponse);

        if ($response instanceof HasTrailers) {
            static::sendTrailers($response, $swooleResponse);

            $content = (string) $response->getContent();
            static::end($swooleResponse, $content === '' ? null : $content);

            return;
        }

        static::end($swooleResponse, (string) $response->getContent());
    }

    /**
     * Send status code, headers, and cookies to Swoole.
     */
    protected static function sendStatusAndHeaders(Response $response, SwooleResponse $swooleResponse): void
    {
        if ($swooleResponse->status($response->getStatusCode()) === false) {
            throw new RuntimeException('Unable to set the response status.');
        }

        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $value = count($values) === 1 ? $values[0] : $values;

            if ($swooleResponse->header($name, $value) === false) {
                throw new RuntimeException('Unable to set a response header.');
            }
        }

        foreach ($response->headers->getCookies() as $cookie) {
            if ($swooleResponse->cookie(
                $cookie->getName(),
                $cookie->getValue() ?? '',
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain() ?? '',
                $cookie->isSecure(),
                $cookie->isHttpOnly(),
                $cookie->getSameSite() ?? ''
            ) === false) {
                throw new RuntimeException('Unable to set a response cookie.');
            }
        }
    }

    /**
     * Announce the trailer names known before response emission.
     */
    protected static function announceTrailers(Response $response): void
    {
        if (! $response instanceof HasTrailers) {
            return;
        }

        $names = [];

        foreach ($response->headers->all('Trailer') as $value) {
            foreach (explode(',', $value) as $name) {
                $names[] = trim($name);
            }
        }

        array_push($names, ...$response->trailerNames());

        $normalizedNames = static::normalizeTrailerNames($names);

        if ($normalizedNames === []) {
            $response->headers->remove('Trailer');

            return;
        }

        $response->headers->set('Trailer', implode(', ', $normalizedNames));
    }

    /**
     * Send the final response trailers.
     */
    protected static function sendTrailers(HasTrailers $response, SwooleResponse $swooleResponse): void
    {
        $trailers = [];
        $seenNames = [];

        foreach ($response->trailers() as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw new RuntimeException('Response trailer names and values must be strings.');
            }

            $normalizedName = static::normalizeTrailerName($name);

            if (isset($seenNames[$normalizedName])) {
                throw new RuntimeException('Response trailer names must be unique after normalization.');
            }

            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new RuntimeException('Response trailer values cannot contain line breaks.');
            }

            $seenNames[$normalizedName] = true;
            $trailers[$normalizedName] = $value;
        }

        foreach ($trailers as $name => $value) {
            if ($swooleResponse->trailer($name, $value) === false) {
                throw new RuntimeException('Unable to set a response trailer.');
            }
        }
    }

    /**
     * Normalize and validate response trailer names.
     *
     * @param array<array-key, mixed> $names
     * @return list<string>
     */
    protected static function normalizeTrailerNames(array $names): array
    {
        $normalizedNames = [];
        $seenNames = [];

        foreach ($names as $name) {
            if (! is_string($name)) {
                throw new RuntimeException('Response trailer names must be strings.');
            }

            $normalizedName = static::normalizeTrailerName($name);

            if (isset($seenNames[$normalizedName])) {
                continue;
            }

            $seenNames[$normalizedName] = true;
            $normalizedNames[] = $normalizedName;
        }

        return $normalizedNames;
    }

    /**
     * Normalize and validate one response trailer name.
     */
    protected static function normalizeTrailerName(string $name): string
    {
        if (strlen($name) > 127) {
            throw new RuntimeException('Response trailer names cannot exceed 127 bytes.');
        }

        if (preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/D", $name) !== 1) {
            throw new RuntimeException('Response trailer names must be valid HTTP field names.');
        }

        $normalizedName = strtolower($name);

        if (in_array($normalizedName, static::FORBIDDEN_TRAILER_NAMES, true)) {
            throw new RuntimeException('The response trailer name is forbidden.');
        }

        return $normalizedName;
    }

    /**
     * Stream a Symfony StreamedResponse through Swoole's write() method.
     *
     * Retained iterables are consumed directly so a failed write stops their
     * producer. Ordinary callbacks use echo, so their output is routed through
     * a non-throwing output-buffer handler instead.
     *
     * The chunk_size of 1 means the buffer flushes after every output
     * operation that produces 1+ bytes (not per byte). A single
     * `echo "data: ...\n\n"` triggers one write() call with the full string.
     *
     * Removable output buffers are cleaned without spinning on a non-removable
     * user buffer before the earliest callback, write, or end failure is rethrown.
     */
    protected static function sendStreamedContent(StreamedResponse $response, SwooleResponse $swooleResponse): void
    {
        if ($response instanceof IterableStreamedResponse
            && static::sendIterableContent($response, $swooleResponse)) {
            return;
        }

        if ($response instanceof HasTrailers) {
            static::sendTrailerStreamedContent($response, $swooleResponse);

            return;
        }

        $level = ob_get_level();
        $exception = null;
        $writable = true;

        ob_start(function (string $chunk) use ($swooleResponse, &$exception, &$writable): string {
            if ($chunk === '' || ! $writable) {
                return '';
            }

            try {
                $writable = $swooleResponse->write($chunk);
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
                $writable = false;
            }

            return '';
        }, 1);

        try {
            try {
                $response->sendContent();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        } finally {
            static::restoreOutputBufferLevel($level);

            try {
                $swooleResponse->end();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Stream retained iterable chunks directly through Swoole.
     */
    protected static function sendIterableContent(IterableStreamedResponse $response, SwooleResponse $swooleResponse): bool
    {
        if ($response instanceof HasTrailers) {
            return static::sendTrailerIterableContent($response, $swooleResponse);
        }

        $exception = null;

        try {
            $handled = $response->streamTo(
                static fn (string $chunk): bool => $chunk === '' || $swooleResponse->write($chunk)
            );
        } catch (Throwable $throwable) {
            $handled = true;
            $exception = $throwable;
        }

        if (! $handled) {
            return false;
        }

        try {
            $swooleResponse->end();
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        if ($exception !== null) {
            throw $exception;
        }

        return true;
    }

    /**
     * Stream retained iterable chunks with one-chunk trailer lookahead.
     */
    protected static function sendTrailerIterableContent(
        IterableStreamedResponse&HasTrailers $response,
        SwooleResponse $swooleResponse
    ): bool {
        $pendingChunk = null;
        $writeFailed = false;

        $handled = $response->streamTo(function (string $chunk) use (&$pendingChunk, &$writeFailed, $swooleResponse): bool {
            if ($chunk === '') {
                return true;
            }

            if ($pendingChunk !== null && $swooleResponse->write($pendingChunk) === false) {
                $writeFailed = true;

                return false;
            }

            $pendingChunk = $chunk;

            return true;
        });

        if (! $handled) {
            return false;
        }

        if ($writeFailed) {
            throw new RuntimeException('Unable to write the streamed response.');
        }

        static::sendTrailers($response, $swooleResponse);
        static::end($swooleResponse, $pendingChunk);

        return true;
    }

    /**
     * Stream a trailer-bearing callback response through an output buffer.
     */
    protected static function sendTrailerStreamedContent(
        StreamedResponse&HasTrailers $response,
        SwooleResponse $swooleResponse
    ): void {
        $pendingChunk = null;
        $writeFailed = false;
        $writeFailure = null;
        $producerFailure = null;
        $level = ob_get_level();

        // Output handlers cannot safely throw: PHP may bypass a failing handler
        // and emit the original chunk to process output.
        ob_start(function (string $chunk) use (
            &$pendingChunk,
            &$writeFailed,
            &$writeFailure,
            $swooleResponse
        ): string {
            if ($chunk === '' || $writeFailed) {
                return '';
            }

            if ($pendingChunk !== null) {
                try {
                    $writeFailed = $swooleResponse->write($pendingChunk) === false;
                } catch (Throwable $throwable) {
                    $writeFailure = $throwable;
                    $writeFailed = true;
                }

                if ($writeFailed) {
                    return '';
                }
            }

            $pendingChunk = $chunk;

            return '';
        }, 1);

        try {
            try {
                $response->sendContent();
            } catch (Throwable $throwable) {
                $producerFailure = $throwable;
            }
        } finally {
            static::restoreOutputBufferLevel($level);
        }

        if ($writeFailure !== null) {
            throw $writeFailure;
        }

        if ($writeFailed) {
            throw new RuntimeException(
                'Unable to write the streamed response.',
                previous: $producerFailure,
            );
        }

        if ($producerFailure !== null) {
            throw $producerFailure;
        }

        static::sendTrailers($response, $swooleResponse);
        static::end($swooleResponse, $pendingChunk);
    }

    /**
     * Restore removable output buffers after streamed response production.
     */
    protected static function restoreOutputBufferLevel(int $level): void
    {
        while (ob_get_level() > $level) {
            $status = ob_get_status();

            if (($status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) === 0) {
                break;
            }

            ob_end_clean();
        }
    }

    /**
     * Complete a response and require native success.
     */
    protected static function end(SwooleResponse $swooleResponse, ?string $content = null): void
    {
        $ended = $content === null
            ? $swooleResponse->end()
            : $swooleResponse->end($content);

        if ($ended === false) {
            throw new RuntimeException('Unable to complete the response.');
        }
    }

    /**
     * Send a response file and require native success.
     */
    protected static function sendFile(SwooleResponse $swooleResponse, string $path): void
    {
        if ($swooleResponse->sendfile($path) === false) {
            throw new RuntimeException('Unable to send the response file.');
        }
    }
}
