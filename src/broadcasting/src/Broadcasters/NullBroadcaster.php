<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Broadcasters;

use Hypervel\Http\Request;

class NullBroadcaster extends Broadcaster
{
    public function auth(Request $request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse(Request $request, mixed $result): mixed
    {
        return null;
    }

    public function broadcast(array $channels, string $event, array $payload = []): void
    {
    }
}
