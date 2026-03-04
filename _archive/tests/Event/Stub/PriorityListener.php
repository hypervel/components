<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event\Stub;

use Hypervel\Event\Contracts\ListenerInterface;

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
