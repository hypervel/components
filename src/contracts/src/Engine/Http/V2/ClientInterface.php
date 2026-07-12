<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http\V2;

interface ClientInterface
{
    /**
     * Configure the client.
     */
    public function set(array $settings): bool;

    /**
     * @return int StreamID
     */
    public function send(RequestInterface $request): int;

    /**
     * Receive a response.
     */
    public function recv(float $timeout = 0): ResponseInterface;

    /**
     * Write data to a stream.
     */
    public function write(int $streamId, mixed $data, bool $end = false): bool;

    /**
     * Send a ping frame.
     */
    public function ping(): bool;

    /**
     * Close the client.
     */
    public function close(): bool;

    /**
     * Determine whether the client is connected.
     */
    public function isConnected(): bool;
}
