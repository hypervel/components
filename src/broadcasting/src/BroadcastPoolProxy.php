<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Closure;
use Hypervel\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Hypervel\Contracts\Broadcasting\Broadcaster;
use Hypervel\Contracts\Broadcasting\HasBroadcastChannel;
use Hypervel\Http\Request;
use Hypervel\ObjectPool\PoolProxy;
use Hypervel\Support\Collection;

class BroadcastPoolProxy extends PoolProxy implements Broadcaster
{
    /**
     * The callback to resolve the authenticated user information.
     */
    protected ?Closure $authenticatedUserCallback = null;

    /**
     * Resolve the authenticated user payload for the incoming connection request.
     */
    public function resolveAuthenticatedUser(Request $request): ?array
    {
        /** @var BaseBroadcaster $driver */
        $driver = $this->pool->get();

        try {
            // The resolver must be applied to the borrowed broadcaster before forwarding the call.
            if ($this->authenticatedUserCallback) {
                $driver->resolveAuthenticatedUserUsing($this->authenticatedUserCallback);
            }

            return $driver->resolveAuthenticatedUser($request);
        } finally {
            if ($this->releaseCallback) {
                ($this->releaseCallback)($driver);
            }

            $this->pool->release($driver);
        }
    }

    /**
     * Register the user retrieval callback used to authenticate connections.
     */
    public function resolveAuthenticatedUserUsing(Closure $callback): void
    {
        $this->authenticatedUserCallback = $callback;
    }

    /**
     * Register a channel authenticator.
     */
    public function channel(HasBroadcastChannel|string $channel, callable|string $callback, array $options = []): static
    {
        $this->__call(__FUNCTION__, func_get_args());

        return $this;
    }

    public function auth(Request $request): mixed
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Return the valid authentication response.
     */
    public function validAuthenticationResponse(Request $request, mixed $result): mixed
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Broadcast the given event.
     */
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get all of the registered channels.
     */
    public function getChannels(): Collection
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }
}
