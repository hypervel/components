<?php

declare(strict_types=1);

namespace Hypervel\Server\Entry;

use Closure;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Events\QueuedClosure;

/**
 * No-op fallback dispatcher used by ServerFactory when no real dispatcher is set.
 */
class EventDispatcher implements Dispatcher
{
    public function listen(array|Closure|QueuedClosure|string $events, array|Closure|QueuedClosure|string|null $listener = null): void
    {
    }

    public function hasListeners(string $eventName): bool
    {
        return false;
    }

    public function subscribe(object|string $subscriber): void
    {
    }

    public function until(object|string $event, mixed $payload = []): mixed
    {
        return null;
    }

    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): mixed
    {
        return null;
    }

    public function push(string $event, mixed $payload = []): void
    {
    }

    public function flush(string $event): void
    {
    }

    public function forget(string $event): void
    {
    }

    public function forgetPushed(): void
    {
    }
}
