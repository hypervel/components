<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\VarDumper\VarDumper;

class Debugger
{
    /**
     * Dump a request with Symfony VarDumper.
     */
    public static function dumpRequest(PendingRequest $pendingRequest, RequestInterface $request): void
    {
        VarDumper::dump([
            'connector' => $pendingRequest->connector()::class,
            'request' => $pendingRequest->request()::class,
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'headers' => static::headers($request->getHeaders()),
            'body' => static::requestBody($request->getBody()),
        ], 'Saloon Request (' . class_basename($pendingRequest->request()) . ') ->');
    }

    /**
     * Dump a response with Symfony VarDumper.
     */
    public static function dumpResponse(Response $response, ResponseInterface $psrResponse): void
    {
        VarDumper::dump([
            'status' => $response->status(),
            'headers' => static::headers($psrResponse->getHeaders()),
            'body' => $response->body(),
        ], 'Saloon Response (' . class_basename($response->request()) . ') ->');
    }

    /**
     * Terminate the current process.
     */
    public static function terminate(): never
    {
        exit(1);
    }

    /**
     * Normalize headers for debugging output.
     *
     * @param array<string, list<string>> $headers
     * @return array<string, string>
     */
    protected static function headers(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            $normalized[$name] = implode(';', $values);
        }

        return $normalized;
    }

    /**
     * Read a request body without changing its position.
     */
    protected static function requestBody(StreamInterface $body): string
    {
        if (! $body->isSeekable()) {
            return '[non-seekable stream]';
        }

        $position = $body->tell();

        try {
            $body->rewind();

            return $body->getContents();
        } finally {
            $body->seek($position);
        }
    }
}
