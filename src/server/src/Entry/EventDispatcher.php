<?php

declare(strict_types=1);

namespace Hypervel\Server\Entry;

use Psr\EventDispatcher\EventDispatcherInterface;

class EventDispatcher implements EventDispatcherInterface
{
    /**
     * Dispatch an event (no-op fallback).
     */
    public function dispatch(object $event): object
    {
        return $event;
    }
}
