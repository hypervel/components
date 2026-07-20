<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http\V2;

interface ClientInterface
{
    /**
     * Send an HTTP/2 request.
     *
     * @return int Stream identifier
     */
    public function send(RequestInterface $request, ?float $timeout = null): int;

    /**
     * Receive a response.
     */
    public function recv(float $timeout = 0): ?ResponseInterface;

    /**
     * Write data to a stream.
     */
    public function write(
        int $streamId,
        string $data,
        bool $end = false,
        ?float $timeout = null,
    ): void;

    /**
     * Close the client.
     */
    public function close(): void;

    /**
     * Determine whether the client is connected.
     */
    public function isConnected(): bool;

    /**
     * Determine whether the stream remains open.
     */
    public function isStreamOpen(int $streamId): bool;
}
