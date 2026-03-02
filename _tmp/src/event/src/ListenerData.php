<?php

declare(strict_types=1);

namespace Hypervel\Event;

class ListenerData
{
    public const DEFAULT_PRIORITY = 0;

    /**
     * @var callable
     */
    public $listener;

    /**
     * Create a new listener data instance.
     */
    public function __construct(public string $event, callable $listener, public int $priority)
    {
        $this->listener = $listener;
    }
}
