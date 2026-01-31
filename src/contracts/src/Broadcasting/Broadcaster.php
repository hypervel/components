<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Broadcasting;

use Hyperf\HttpServer\Contract\RequestInterface;

interface Broadcaster
{
    /**
     * Authenticate the incoming request for a given channel.
     */
    public function auth(RequestInterface $request): mixed;

    /**
     * Return the valid authentication response.
     */
    public function validAuthenticationResponse(RequestInterface $request, mixed $result): mixed;

    /**
     * Broadcast the given event.
     */
    public function broadcast(array $channels, string $event, array $payload = []): void;
}
