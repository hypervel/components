<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Broadcasters;

use Hyperf\HttpServer\Contract\RequestInterface;

class NullBroadcaster extends Broadcaster
{
    public function auth(RequestInterface $request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse(RequestInterface $request, mixed $result): mixed
    {
        return null;
    }

    public function broadcast(array $channels, string $event, array $payload = []): void
    {
    }
}
