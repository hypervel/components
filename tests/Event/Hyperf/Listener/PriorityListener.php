<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event\Hyperf\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Hypervel\Tests\Event\Hyperf\Event\PriorityEvent;

class PriorityListener implements ListenerInterface
{
    protected $id;

    public function __construct($id)
    {
        $this->id = $id;
    }

    public function listen(): array
    {
        return [
            PriorityEvent::class,
        ];
    }

    /**
     * @param PriorityEvent $event
     */
    public function process(object $event): void
    {
        PriorityEvent::$result[] = $this->id;
    }
}
