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
use RuntimeException;

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
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Register the user retrieval callback used to authenticate connections.
     *
     * Boot-only. The callback persists in instance state on the cached pool
     * proxy for the worker lifetime; per-request use races across coroutines.
     */
    public function resolveAuthenticatedUserUsing(?Closure $callback): void
    {
        $this->authenticatedUserCallback = $callback;
    }

    /**
     * Register a channel authenticator.
     *
     * Boot-only. Delegates to the underlying broadcaster; the channel
     * authorizer and options persist in shared static state on the
     * Broadcaster class for the worker lifetime.
     */
    public function channel(HasBroadcastChannel|string $channel, callable|string $callback, array $options = []): static
    {
        $this->invoke(__FUNCTION__, func_get_args());

        return $this;
    }

    public function auth(Request $request): mixed
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Return the valid authentication response.
     */
    public function validAuthenticationResponse(Request $request, mixed $result): mixed
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Broadcast the given event.
     */
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Get all of the registered channels.
     */
    public function getChannels(): Collection
    {
        return $this->invoke(__FUNCTION__, func_get_args());
    }

    /**
     * Apply this proxy's authenticated-user callback to a borrowed broadcaster.
     */
    protected function configureBorrowed(object $object): void
    {
        if ($object instanceof BaseBroadcaster) {
            $object->resolveAuthenticatedUserUsing($this->authenticatedUserCallback);

            return;
        }

        if ($this->authenticatedUserCallback !== null) {
            throw new RuntimeException(
                'Authenticated-user resolver callbacks on pooled broadcasters require an instance of '
                . BaseBroadcaster::class . '; [' . $object::class . '] was returned.'
            );
        }
    }
}
