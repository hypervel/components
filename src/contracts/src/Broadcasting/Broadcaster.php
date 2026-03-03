<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Broadcasting;

use Hypervel\Http\Request;

interface Broadcaster
{
    /**
     * Authenticate the incoming request for a given channel.
     */
    public function auth(Request $request): mixed;

    /**
     * Return the valid authentication response.
     */
    public function validAuthenticationResponse(Request $request, mixed $result): mixed;

    /**
     * Broadcast the given event.
     */
    public function broadcast(array $channels, string $event, array $payload = []): void;
}
