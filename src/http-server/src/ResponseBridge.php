<?php

declare(strict_types=1);

namespace Hypervel\HttpServer;

use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\Response as HypervelResponse;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ResponseBridge
{
    /**
     * Send an HttpFoundation response through Swoole.
     */
    public static function send(Response $response, SwooleResponse $swooleResponse, bool $withBody = true): void
    {
        // If the response was already streamed directly to the client
        // (via Hypervel's Response::stream() direct Swoole write path),
        // the data is already sent. Do not double-send.
        if ($response instanceof HypervelResponse && $response->isStreamed()) {
            $swooleResponse->end();

            return;
        }

        if (! $withBody) {
            static::sendStatusAndHeaders($response, $swooleResponse);
            $swooleResponse->end();

            return;
        }

        // Body — dispatch by response type
        if ($response instanceof BinaryFileResponse) {
            static::sendStatusAndHeaders($response, $swooleResponse);
            $swooleResponse->sendfile($response->getFile()->getPathname());
        } elseif ($response instanceof StreamedResponse) {
            // Swoole's write() uses chunked transfer encoding. Content-Length
            // and Transfer-Encoding headers conflict with this — Swoole raises
            // an ErrorException if Content-Length is set before write().
            // See: https://github.com/laravel/octane/issues/670
            $response->headers->remove('Content-Length');
            $response->headers->remove('Transfer-Encoding');

            static::sendStatusAndHeaders($response, $swooleResponse);
            static::sendStreamedContent($response, $swooleResponse);
        } else {
            static::sendStatusAndHeaders($response, $swooleResponse);
            $swooleResponse->end($response->getContent());
        }
    }

    /**
     * Send status code, headers, and cookies to Swoole.
     */
    protected static function sendStatusAndHeaders(Response $response, SwooleResponse $swooleResponse): void
    {
        $swooleResponse->status($response->getStatusCode());

        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ($values as $value) {
                $swooleResponse->header($name, $value);
            }
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $swooleResponse->cookie(
                $cookie->getName(),
                $cookie->getValue() ?? '',
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain() ?? '',
                $cookie->isSecure(),
                $cookie->isHttpOnly(),
                $cookie->getSameSite() ?? ''
            );
        }
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
            while (ob_get_level() > $level) {
                $status = ob_get_status();

                if (($status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) === 0) {
                    break;
                }

                ob_end_clean();
            }

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
        $exception = null;

        try {
            $handled = $response->streamTo(
                static fn (string $chunk): bool => $swooleResponse->write($chunk)
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
}
