<?php

declare(strict_types=1);

namespace Hypervel\Event\Contracts;

interface ListenerInterface
{
    /**
     * Get the events the listener should listen for.
     *
     * @return string[]
     */
    public function listen(): array;

    /**
     * Handle the given event.
     */
    public function process(object $event): void;
}
