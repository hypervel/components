<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;

class DispatcherStub implements EventDispatcherInterface
{
    protected $listener;

    public function dispatch(object $event)
    {
        if (! $this->listener) {
            return;
        }

        ($this->listener)($event);
    }

    public function listen(string $event, Closure $listener)
    {
        $this->listener = $listener;
    }
}
