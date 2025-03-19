<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Hyperf\Event\ListenerData as BaseListenerData;

class ListenerData extends BaseListenerData
{
    /**
     * @phpstan-ignore-next-line
     * @var array|callable|string
     */
    public $listener;

    public function __construct(string $event, array|callable|string $listener, int $priority)
    {
        $this->event = $event;
        $this->listener = $listener;
        $this->priority = $priority;
    }
}
