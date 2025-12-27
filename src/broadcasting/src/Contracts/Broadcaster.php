<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Contracts;

use Hyperf\HttpServer\Contract\RequestInterface;

interface Broadcaster
{
    /**
     * Register a channel authenticator.
     */
    public function channel(HasBroadcastChannel|string $channel, callable|string $callback, array $options = []): static;

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
