<?php

declare(strict_types=1);

namespace Hypervel\Framework;

use Psr\EventDispatcher\EventDispatcherInterface as PsrDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

if (interface_exists(EventDispatcherInterface::class)) {
    /**
     * @internal
     */
    class SymfonyEventDispatcher implements EventDispatcherInterface
    {
        public function __construct(private PsrDispatcherInterface $psrDispatcher)
        {
        }

        /**
         * Dispatch an event via the PSR-14 dispatcher.
         */
        public function dispatch(object $event, ?string $eventName = null): object
        {
            return $this->psrDispatcher->dispatch($event);
        }
    }
}
