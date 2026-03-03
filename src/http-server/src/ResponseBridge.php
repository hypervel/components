<?php

declare(strict_types=1);

namespace Hypervel\HttpServer;

use Hypervel\Http\Response as HypervelResponse;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Symfony's StreamedResponse uses echo inside a callback to emit chunks.
     * We intercept each echo via ob_start with a callback that routes the
     * output to Swoole's write(), sending each chunk to the client immediately.
     *
     * The chunk_size of 1 means the buffer flushes after every output
     * operation that produces 1+ bytes (not per byte). A single
     * `echo "data: ...\n\n"` triggers one write() call with the full string.
     *
     * The try/finally with safe OB level restore is critical in Swoole's
     * long-lived workers: if sendContent() throws, the output buffer must
     * be cleaned up to prevent OB level leaks across requests.
     */
    protected static function sendStreamedContent(StreamedResponse $response, SwooleResponse $swooleResponse): void
    {
        $level = ob_get_level();

        ob_start(function (string $chunk) use ($swooleResponse): string {
            if (strlen($chunk) > 0) {
                $swooleResponse->write($chunk);
            }

            return '';
        }, 1);

        try {
            $response->sendContent();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $swooleResponse->end();
    }
}
