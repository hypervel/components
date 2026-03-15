<?php

declare(strict_types=1);

namespace Hypervel\Engine;

use Hypervel\Contracts\Engine\ResponseEmitterInterface;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Http\Response as HypervelResponse;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ResponseEmitter implements ResponseEmitterInterface
{
    /**
     * Create a new response emitter instance.
     */
    public function __construct(protected ?StdoutLoggerInterface $logger)
    {
    }

    /**
     * Emit the response to the client.
     *
     * @param SwooleResponse $connection
     */
    public function emit(Response $response, mixed $connection, bool $withContent = true): void
    {
        try {
            if (strtolower($connection->header['Upgrade'] ?? '') === 'websocket') {
                return;
            }

            // If the response was already streamed directly to the client
            // (via Hypervel's Response::stream() direct Swoole write path),
            // the data is already sent. Do not double-send.
            if ($response instanceof HypervelResponse && $response->isStreamed()) {
                $connection->end();
                return;
            }

            if (! $withContent) {
                $this->sendStatusAndHeaders($connection, $response);
                $connection->end();
                return;
            }

            if ($response instanceof BinaryFileResponse) {
                $this->sendStatusAndHeaders($connection, $response);
                $connection->sendfile($response->getFile()->getPathname());
                return;
            }

            if ($response instanceof StreamedResponse) {
                $response->headers->remove('Content-Length');
                $response->headers->remove('Transfer-Encoding');
                $this->sendStatusAndHeaders($connection, $response);
                $this->sendStreamedContent($response, $connection);
                return;
            }

            $this->sendStatusAndHeaders($connection, $response);
            $connection->end($response->getContent());
        } catch (Throwable $exception) {
            $this->logger?->critical((string) $exception);
        }
    }

    /**
     * Send status code, headers, and cookies to Swoole.
     */
    protected function sendStatusAndHeaders(SwooleResponse $swooleResponse, Response $response): void
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
     */
    protected function sendStreamedContent(StreamedResponse $response, SwooleResponse $swooleResponse): void
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
