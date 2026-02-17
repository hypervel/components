<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http\V2;

interface ClientInterface
{
    public function set(array $settings): bool;

    /**
     * @return int StreamID
     */
    public function send(RequestInterface $request): int;

    public function recv(float $timeout = 0): ResponseInterface;

    public function write(int $streamId, mixed $data, bool $end = false): bool;

    public function ping(): bool;

    public function close(): bool;

    public function isConnected(): bool;
}
