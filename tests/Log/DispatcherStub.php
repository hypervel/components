<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Closure;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Event\QueuedClosure;

class DispatcherStub implements Dispatcher
{
    protected ?Closure $listener = null;

    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): mixed
    {
        if ($this->listener) {
            ($this->listener)($event);
        }

        return null;
    }

    public function listen(array|Closure|QueuedClosure|string $events, array|Closure|QueuedClosure|string|null $listener = null): void
    {
        if ($listener instanceof Closure) {
            $this->listener = $listener;
        }
    }

    public function until(object|string $event, mixed $payload = []): mixed
    {
        return null;
    }

    public function getListeners(object|string $eventName): iterable
    {
        return [];
    }

    public function getListenersForEvent(object $event): iterable
    {
        return [];
    }

    public function push(string $event, mixed $payload = []): void
    {
    }

    public function flush(string $event): void
    {
    }

    public function forgetPushed(): void
    {
    }

    public function forget(string $event): void
    {
    }

    public function hasListeners(string $eventName): bool
    {
        return false;
    }

    public function hasWildcardListeners(string $eventName): bool
    {
        return false;
    }

    public function subscribe(object|string $subscriber): void
    {
    }

    public function getRawListeners(): array
    {
        return [];
    }
}
